<?php
// ai_order_service_advanced.php

require 'database_advanced.php';
require 'session_manager.php';

header('Content-Type: application/json; charset=utf-8');
set_time_limit(60);

class DeepSeekClient {
    private $api_key;
    private $api_url = 'https://api.deepseek.com/v1/chat/completions';
    
    public function __construct($api_key) {
        $this->api_key = $api_key;
    }
    
    public function chat($messages, $tools = null, $timeout = 30) {
        $data = [
            'model' => 'deepseek-chat',
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 2000,
        ];
        
        if ($tools) {
            $data['tools'] = $tools;
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->api_key
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception('cURL Error: ' . $error);
        }
        
        if ($http_code !== 200) {
            throw new Exception('API Error: HTTP ' . $http_code . ' - ' . $response);
        }
        
        return json_decode($response, true);
    }
}

function handleAdvancedChat() {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $user_message = $input['message'] ?? '';
        $session_id = $input['session_id'] ?? 'default';
        
        if (empty($user_message)) {
            throw new Exception('請提供消息內容');
        }
        
        $api_key = '您的-DeepSeek-API-金鑰';
        $client = new DeepSeekClient($api_key);
        $session_manager = new SessionManager($session_id);
        
        // 加載對話歷史
        $messages = $session_manager->loadMessages();
        
        // 系統提示詞 - 支持多產品和確認流程
        $system_prompt = "你是電商客服助手，支持多產品購買和訂單確認流程。

購物流程：
1. 用戶可以多次添加商品到購物車
2. 用戶可以查看購物車內容
3. 用戶確認購買時創建待確認訂單
4. 用戶需要明確確認才能完成訂單

請引導用戶完成整個購買流程。";

        if (empty($messages)) {
            $messages[] = ['role' => 'system', 'content' => $system_prompt];
        }
        
        $messages[] = ['role' => 'user', 'content' => $user_message];
        
        // 判斷意圖
        $intent = detectIntent($user_message, $session_id);
        $tools = getToolDefinitions($intent);
        
        $response = $client->chat($messages, $tools, 30);
        $ai_message = $response['choices'][0]['message'];
        
        $final_reply = '';
        $used_function = false;
        
        if (isset($ai_message['tool_calls']) && !empty($ai_message['tool_calls'])) {
            $messages[] = $ai_message;
            $used_function = true;
            
            $tool_responses = [];
            foreach ($ai_message['tool_calls'] as $tool_call) {
                $function_result = executeAdvancedFunction($tool_call, $session_id);
                $tool_responses[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tool_call['id'],
                    'content' => json_encode($function_result, JSON_UNESCAPED_UNICODE)
                ];
            }
            
            // 🎯 關鍵修正：正確添加 tool 消息
            foreach ($tool_responses as $tool_response) {
                $messages[] = $tool_response;
            }
            
            // 獲取最終回復
            $final_response = $client->chat($messages, null, 30);
            $final_reply = $final_response['choices'][0]['message']['content'];
            $messages[] = ['role' => 'assistant', 'content' => $final_reply];
            
        } else {
            $final_reply = $ai_message['content'];
            $messages[] = ['role' => 'assistant', 'content' => $final_reply];
        }
        
        // 保存對話
        $session_manager->saveMessages($messages);
        
        echo json_encode([
            'success' => true,
            'reply' => $final_reply,
            'used_function' => $used_function,
            'session_id' => $session_id
        ]);
        
    } catch (Exception $e) {
        error_log("Chat Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'reply' => '系統暫時繁忙，請稍後再試。錯誤：' . $e->getMessage()
        ]);
    }
}

/**
 * 意圖檢測
 */
function detectIntent($message, $session_id) {
    $message = mb_strtolower($message);
    
    // 購物車操作
    if (strpos($message, '購物車') !== false) {
        if (strpos($message, '查看') !== false || strpos($message, '顯示') !== false) {
            return 'view_cart';
        }
        if (strpos($message, '清空') !== false) {
            return 'clear_cart';
        }
    }
    
    // 添加商品
    if (preg_match('/(買|購買|添加|加入).*?(\d+).*?(iphone|airpods|macbook|ipad|watch|手機|耳機|電腦|平板|手錶)/i', $message)) {
        return 'add_to_cart';
    }
    
    // 確認訂單
    if (strpos($message, '確認訂單') !== false || strpos($message, '確認購買') !== false) {
        return 'confirm_order';
    }
    
    // 取消訂單
    if (strpos($message, '取消訂單') !== false) {
        return 'cancel_order';
    }
    
    // 創建訂單
    if (strpos($message, '下單') !== false || strpos($message, '結帳') !== false) {
        return 'create_order';
    }
    
    // 查詢訂單
    if (preg_match('/訂單.*?(\d+)/', $message)) {
        return 'query_order';
    }
    
    // 產品列表
    if (strpos($message, '產品') !== false || strpos($message, '商品') !== false) {
        return 'list_products';
    }
    
    return 'general';
}

/**
 * 動態工具定義
 */
function getToolDefinitions($intent) {
    $base_tools = [
        [
            "type" => "function",
            "function" => [
                "name" => "list_products",
                "description" => "獲取可購買的產品列表",
                "parameters" => [
                    "type" => "object",
                    "properties" => (object)[]
                ]
            ]
        ],
        [
            "type" => "function",
            "function" => [
                "name" => "view_cart",
                "description" => "查看購物車內容",
                "parameters" => [
                    "type" => "object", 
                    "properties" => (object)[]
                ]
            ]
        ]
    ];
    
    switch ($intent) {
        case 'add_to_cart':
            $base_tools[] = [
                "type" => "function",
                "function" => [
                    "name" => "add_to_cart",
                    "description" => "添加商品到購物車",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "product_id" => [
                                "type" => "string",
                                "description" => "產品ID",
                                "enum" => ["iphone15", "airpods", "macbook", "ipad", "watch"]
                            ],
                            "quantity" => [
                                "type" => "integer",
                                "description" => "數量",
                                "minimum" => 1
                            ]
                        ],
                        "required" => ["product_id", "quantity"]
                    ]
                ]
            ];
            break;
            
        case 'create_order':
            $base_tools[] = [
                "type" => "function", 
                "function" => [
                    "name" => "create_order",
                    "description" => "創建訂單（需要客戶信息）",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "customer_name" => ["type" => "string"],
                            "customer_phone" => ["type" => "string"], 
                            "customer_address" => ["type" => "string"]
                        ],
                        "required" => ["customer_name", "customer_phone", "customer_address"]
                    ]
                ]
            ];
            break;
            
        case 'confirm_order':
            $base_tools[] = [
                "type" => "function",
                "function" => [
                    "name" => "confirm_order",
                    "description" => "確認訂單",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "order_id" => ["type" => "string"]
                        ],
                        "required" => ["order_id"]
                    ]
                ]
            ];
            break;
            
        case 'query_order':
            $base_tools[] = [
                "type" => "function",
                "function" => [
                    "name" => "query_order", 
                    "description" => "查詢訂單狀態",
                    "parameters" => [
                        "type" => "object",
                        "properties" => [
                            "order_id" => ["type" => "string"]
                        ],
                        "required" => ["order_id"]
                    ]
                ]
            ];
            break;
    }
    
    return $base_tools;
}

/**
 * 執行高級函數
 */
function executeAdvancedFunction($tool_call, $session_id) {
    $function_name = $tool_call['function']['name'];
    $args = json_decode($tool_call['function']['arguments'], true);
    
    switch ($function_name) {
        case 'add_to_cart':
            $result = AdvancedDatabase::addToCart($session_id, $args['product_id'], $args['quantity']);
            if (isset($result['success'])) {
                $cart = $result['cart'];
                $total_items = array_sum(array_column($cart, 'quantity'));
                $total_amount = array_sum(array_column($cart, 'subtotal'));
                $result['summary'] = "購物車現有 {$total_items} 件商品，總金額：¥{$total_amount}";
            }
            return $result;
            
        case 'view_cart':
            $cart = AdvancedDatabase::getCart($session_id);
            if (empty($cart)) {
                return ["message" => "購物車是空的"];
            }
            
            $total_items = array_sum(array_column($cart, 'quantity'));
            $total_amount = array_sum(array_column($cart, 'subtotal'));
            
            $cart_details = "🛒 購物車內容：\n";
            foreach ($cart as $item) {
                $cart_details .= "• {$item['name']} × {$item['quantity']} = ¥{$item['subtotal']}\n";
            }
            $cart_details .= "總計：{$total_items} 件商品，¥{$total_amount}";
            
            return [
                "success" => true,
                "cart" => $cart,
                "message" => $cart_details,
                "total_items" => $total_items,
                "total_amount" => $total_amount
            ];
            
        case 'create_order':
            // 先檢查購物車
            $cart = AdvancedDatabase::getCart($session_id);
            if (empty($cart)) {
                return ["error" => "購物車為空，請先添加商品"];
            }
            
            $result = AdvancedDatabase::createOrderFromCart($session_id, [
                'name' => $args['customer_name'],
                'phone' => $args['customer_phone'],
                'address' => $args['customer_address']
            ]);
            
            if (isset($result['success'])) {
                $order = $result['order_details'];
                $result['confirmation_message'] = "📦 訂單已創建！\n訂單號：{$order['order_id']}\n總金額：¥{$order['total_amount']}\n狀態：待確認\n\n請回覆「確認訂單 {$order['order_id']}」來完成購買，或「取消訂單 {$order['order_id']}」來取消。";
            }
            return $result;
            
        case 'confirm_order':
            $result = AdvancedDatabase::confirmOrder($args['order_id']);
            if (isset($result['success'])) {
                $result['completion_message'] = "✅ 訂單確認成功！\n訂單號：{$args['order_id']}\n我們將盡快為您處理發貨。";
                // 清空購物車
                AdvancedDatabase::clearCart($session_id);
            }
            return $result;
            
        case 'query_order':
            return AdvancedDatabase::queryOrder($args['order_id']);
            
        case 'list_products':
            $products = AdvancedDatabase::getProducts();
            $product_list = "🛍️ 可購買產品：\n";
            foreach ($products as $product) {
                $product_list .= "• {$product['name']} - ¥{$product['price']} (庫存: {$product['stock']})\n";
            }
            return [
                "success" => true,
                "products" => $products,
                "message" => $product_list
            ];
            
        default:
            return ["error" => "未知功能: " . $function_name];
    }
}

// 處理請求
handleAdvancedChat();
?>
