<?php
// session_manager.php

class SessionManager {
    private $session_id;
    private $session_path = 'sessions/';
    
    public function __construct($session_id) {
        $this->session_id = $session_id;
        if (!is_dir($this->session_path)) {
            mkdir($this->session_path, 0755, true);
        }
    }
    
    public function loadMessages() {
        $file = $this->getSessionFile();
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            // 檢查是否過期（24小時）
            if (time() - ($data['last_activity'] ?? 0) < 86400) {
                return $data['messages'] ?? [];
            }
        }
        return [];
    }
    
    public function saveMessages($messages) {
        $file = $this->getSessionFile();
        
        // 限制消息數量，避免過長
        if (count($messages) > 20) {
            $messages = array_merge(
                [$messages[0]], // 保持system
                array_slice($messages, -18) // 保留最近9輪對話
            );
        }
        
        file_put_contents($file, json_encode([
            'session_id' => $this->session_id,
            'last_activity' => time(),
            'messages' => $messages
        ], JSON_UNESCAPED_UNICODE));
    }
    
    private function getSessionFile() {
        return $this->session_path . $this->session_id . '.json';
    }
    
    public static function cleanupOldSessions($max_age_hours = 24) {
        $files = glob('sessions/*.json');
        $now = time();
        $max_age = $max_age_hours * 3600;
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($now - ($data['last_activity'] ?? 0) > $max_age) {
                unlink($file);
            }
        }
    }
}

// 處理聊天請求
function handleChat() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $user_message = $input['message'] ?? '';
        $session_id = $input['session_id'] ?? 'default';
        
        if (empty($user_message)) {
            throw new Exception('請提供消息內容');
        }
        
        // 初始化組件
        $api_key = ''; // 請替換為您的API密鑰
        $openai = new OpenAIClient($api_key);
        $rag_engine = new RAGEngine();
        $session_manager = new SessionManager($session_id);
        
        // 加載對話歷史
        $existing_messages = $session_manager->loadMessages();
        
        // 系統提示詞
        $system_prompt = "你是智能客服助手，根據檢索到的知識庫信息和可用工具來幫助用戶。回答要準確、友好、精簡、段落清晰。";
        
        // 如果是新會話，添加系統提示
        if (empty($existing_messages)) {
            $existing_messages[] = ['role' => 'system', 'content' => $system_prompt];
        }
        
        // 添加用戶新消息
        $existing_messages[] = ['role' => 'user', 'content' => $user_message];
        
        // 判斷問題類型
        $question_type = determineQuestionType($user_message);
        $used_rag = false;
        $used_function = false;
        
        if ($question_type === 'knowledge' && false) {
            // 使用 RAG 處理知識型問題
            $relevant_docs = $rag_engine->search($user_message, 3);
            $context_text = "請根據以下參考信息準確回答用戶問題：\n\n";
            
            foreach ($relevant_docs as $doc) {
                $context_text .= "📚 " . $doc['content'] . "\n\n";
            }
            
            $context_text .= "用戶問題：" . $user_message;
            
            $rag_messages = [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $context_text]
            ];
            
            $response = $openai->chat($rag_messages, null, 30);
            $final_reply = $response['choices'][0]['message']['content'];
            $used_rag = true;
            
            // 更新對話歷史
            $existing_messages[] = ['role' => 'assistant', 'content' => $final_reply];
            
        } else {
            // 使用函數調用處理操作型問題
            $tools = getToolDefinitions();
            
            $response = $openai->chat($existing_messages, $tools, 30);
            $choice = $response['choices'][0];
            $message = $choice['message'];
            
            if (isset($message['tool_calls']) && !empty($message['tool_calls'])) {
                $existing_messages[] = $message;
                $used_function = true;
                
                foreach ($message['tool_calls'] as $tool_call) {
                    $function_name = $tool_call['function']['name'];
                    $function_args = json_decode($tool_call['function']['arguments'], true);
                    
                    if ($function_name === 'query_order') {
                        $function_response = Database::queryOrder($function_args['order_id']);
                        
                        $function_response['_ai_instructions'] = <<<INSTRUCTIONS
                        請用清晰友好的方式顯示訂單信息。

                        顯示格式：
                        📦 訂單號：{order_id}
                        👤 客戶：{customer_name}  
                        🔄 狀態：{status}
                        💰 總金額：¥{total_amount}

                        {if tracking_number}🚚 物流單號：{tracking_number}{endif}
                                
                        如有任何疑問，歡迎隨時聯繫我們 12345678！
                        要求：
                        - 只顯示必要信息
                        INSTRUCTIONS;
                        
                    } elseif ($function_name === 'create_order') {
                        $function_response = Database::createOrder(
                            $function_args['product_id'],
                            $function_args['quantity'],
                            [
                                'name' => $function_args['customer_name'],
                                'phone' => $function_args['customer_phone'] ?? '',
                                'address' => $function_args['customer_address'] ?? ''
                            ]
                        );
                    } elseif ($function_name === 'list_products') {
                        $function_response = Database::getProducts();
                    }
                    
                    $existing_messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $tool_call['id'],
                        'content' => json_encode($function_response, JSON_UNESCAPED_UNICODE)
                    ];
                }
                
                $second_response = $openai->chat($existing_messages, null, 30);
                $final_reply = $second_response['choices'][0]['message']['content'];
                $existing_messages[] = ['role' => 'assistant', 'content' => $final_reply];
                
            } else {
                $final_reply = $message['content'];
                $existing_messages[] = ['role' => 'assistant', 'content' => $final_reply];
            }
        }
        
        // 保存更新後的對話歷史
        $session_manager->saveMessages($existing_messages);
        
        // 定期清理舊會話
        if (rand(1, 10) === 1) {
            SessionManager::cleanupOldSessions();
        }
        
        echo json_encode([
            'success' => true,
            'reply' => nl2br($final_reply),
            'used_rag' => $used_rag,
            'used_function' => $used_function,
            'session_id' => $session_id
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'reply' => '系統繁忙，請稍後再試。錯誤：' . $e->getMessage()
        ]);
    }
}

function determineQuestionType($message) {
    $knowledge_keywords = ['什麼', '怎麼', '如何', '為什麼', '多久', '多少錢', '政策', '規定', '介紹', '說明', '保修', '保養', '維護'];
    $operation_keywords = ['訂單', '查詢', '創建', '購買', '買', '下單', '發貨', '物流'];
    
    foreach ($operation_keywords as $keyword) {
        if (mb_strpos($message, $keyword) !== false) {
            return 'operation';
        }
    }
    
    foreach ($knowledge_keywords as $keyword) {
        if (mb_strpos($message, $keyword) !== false) {
            return 'knowledge';
        }
    }
    
    return 'knowledge'; // 默認使用 RAG
}


function getToolDefinitions() {
    return [
        [
            "type" => "function",
            "function" => [
                "name" => "query_order",
                "description" => "根據訂單號查詢訂單狀態和物流信息",
                "parameters" => [
                    "type" => "object",
                    "properties" => [
                        "order_id" => [
                            "type" => "string",
                            "description" => "訂單號碼，例如 12345",
                        ]
                    ],
                    "required" => ["order_id"],
                    "additionalProperties" => false
                ],
            ]
        ],
        [
            "type" => "function", 
            "function" => [
                "name" => "create_order",
                "description" => "創建新訂單",
                "parameters" => [
                    "type" => "object",
                    "properties" => [
                        "product_id" => [
                            "type" => "string",
                            "description" => "產品ID：iphone15, airpods, macbook",
                            "enum" => ["iphone15", "airpods", "macbook"]
                        ],
                        "quantity" => [
                            "type" => "integer", 
                            "description" => "購買數量",
                            "minimum" => 1,
                            "maximum" => 99
                        ],
                        "customer_name" => [
                            "type" => "string",
                            "description" => "客戶姓名",
                        ],
                        "customer_phone" => [
                            "type" => "string",
                            "description" => "客戶電話",
                        ],
                        "customer_address" => [
                            "type" => "string", 
                            "description" => "收貨地址",
                        ]
                    ],
                    "required" => ["product_id", "quantity", "customer_name"],
                    "additionalProperties" => false
                ],
            ]
        ],
        [
            "type" => "function",
            "function" => [
                "name" => "list_products", 
                "description" => "獲取可購買的產品列表",
                "parameters" => [
                    "type" => "object",
                    "properties" => new stdClass(), // 空對象的正確表示
                    "additionalProperties" => false
                ]
            ]
        ]
    ];
}
?>
