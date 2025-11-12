<?php
function doAsk() {
    $input = json_decode(file_get_contents('php://input'), true);
    echo json_encode((new aiChatBot($input['session_id'] ?? 'default'))->clientQuestion($input['message'] ?? ''));
}

function doReply() {
    $input = json_decode(file_get_contents('php://input'), true);
    echo json_encode((new aiChatBot($input['session_id'] ?? 'default'))->tryReply($input['message'] ?? ''));
}

class aiChatBot {
    private $_debugMode = false;
    private $_sessionID;
    private $_sessionPath = 'sessions/';
    private $_intentInfo = [];
    private $_extraInfo1 = [];
    private $_extraInfo2 = [];
    private $_maxRoundsDialogue = 5;

    private $_apiKey = 'xxxxx';
    private $_apiURL = 'https://api.deepseek.com/v1/chat/completions';
    private $_apiModel = 'deepseek-chat';

    public function __construct($sessionID, $debugMode = false) {
        $this->_sessionID = $sessionID;
        if (!is_dir($this->_sessionPath)) {
            mkdir($this->_sessionPath, 0755, true);
        }
        $this->_debugMode = $debugMode;
    }
    
    // Log functions
    public function loadMessages() {
        $file = $this->getSessionFile();
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            if (time() - ($data['last_activity'] ?? 0) < 86400) {
                return $data['messages'] ?? [];
            }
        }
        return [];
    }
    
    public function saveMessages($txt, $role) {
        $client_message = [];
        $file = $this->getSessionFile();
        if (file_exists($file)) {
            $data = json_decode(file_get_contents($file), true);
            $client_message = $data['messages'] ?? [];
        }
        $client_message[] = ['role' => $role, 'content' => $txt];
        
        file_put_contents($file, json_encode([
            '_sessionID'    =>  $this->_sessionID,
            'last_activity' =>  time(),
            'messages'      =>  $client_message
        ], JSON_UNESCAPED_UNICODE));
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
    
    public function getSessionFile() {
        return $this->_sessionPath . $this->_sessionID . '.json';
    }
    
    // Question & answer
    public function clientQuestion($client_message = '') {
        $result = 
        [
            'sessionID' =>  $this->_sessionID,
            'action'    =>  'ask',
            'message'   =>  $client_message,
            'feedback'  =>  $client_message
        ];
        preg_match('/(^#LRAG#)(.*)/ui', $client_message, $lrag_match_output);
    
        // Shortcut question
        if(!empty($lrag_match_output)) {
            $localFAQ = MockDatabase::queryFAQ($lrag_match_output[2]);
            if(!empty($localFAQ)) {
                $result['feedback'] = $localFAQ['title'];
            }
            else {
                $localProduct = MockDatabase::queryProducts($lrag_match_output[2]);
                if(!empty($localProduct)) {
                    $result['feedback'] = '請問 "'.$localProduct['title'].'" 嘅資訊？';
                }
            }
        }
        
        return $result;  
    }
    
    public function tryReply($client_message = '') {
        $result = 
        [
            'sessionID' =>  $this->_sessionID,
            'action'    =>  'reply',
            'message'   =>  $client_message,
            'feedback'  =>  ''
        ];
        preg_match('/(^#LRAG#)(.*)/ui', $client_message, $lrag_match_output);

        // Shortcut answer
        if(!empty($lrag_match_output)) {
            $localFAQ = MockDatabase::queryFAQ($lrag_match_output[2]);
            if(!empty($localFAQ)) {
                $result['feedback'] = nl2br(str_replace(['\r\n', '\n'], PHP_EOL, $localFAQ['description']));
            }
            else {
                $localProduct = MockDatabase::queryProducts($lrag_match_output[2]);
                if(!empty($localProduct)) {
                    $result['feedback'] = nl2br(str_replace(['\r\n', '\n'], PHP_EOL, implode(PHP_EOL, [
                        $localProduct['title'].' ('.$localProduct['origin'].')',
                        $localProduct['description'],
                        $localProduct['price_currency'].' '.number_format($localProduct['price']).'/'.$localProduct['price_unit']
                    ])));
                }
            }
        }

        // RAG answer
        else {
            $localFAQ = MockDatabase::queryFAQEmbeding($client_message);
            if(!empty($localFAQ)) {
                $result['feedback'] = nl2br(str_replace(['\r\n', '\n'], PHP_EOL, $localFAQ['description']));
            }
            else {
                $localProduct = MockDatabase::queryProductsEmbeding($client_message);
                if(!empty($localProduct)) {
                    $result['feedback'] = nl2br(str_replace(['\r\n', '\n'], PHP_EOL, implode(PHP_EOL, [
                        $localProduct['title'].' ('.$localProduct['origin'].')',
                        $localProduct['description'],
                        $localProduct['price_currency'].' '.number_format($localProduct['price']).'/'.$localProduct['price_unit']
                    ])));
                }
            }
        }

        // AI helper
        if(empty($result['feedback'])) {
            $result['feedback'] = nl2br(str_replace(['\r\n', '\n'], PHP_EOL, $this->chat($client_message)));
            $result['intent'] = $this->_intentInfo;
            $result['extra1'] = $this->_extraInfo1;
            $result['extra2'] = $this->_extraInfo2;
        }
        else {
            // Save history log
            $this->saveMessages($result['message'], 'user');
            $this->saveMessages($result['feedback'], 'assistant');
        }
        
        return $result;
    }

    // Via curl, call api
    public function doCurl($client_message, $tools = null, $timeout = 30) {
        $data = [
            'model'         =>  $this->_apiModel,
            'messages'      =>  $client_message,
            //'temperature'   =>  1,
            'max_tokens'    =>  600,
        ];
        
        if ($tools) {
            $data['tools'] = $tools;
            $data['tool_choice'] = 'auto';
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->_apiURL,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->_apiKey
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

    // Core
    function chat($client_message) {
        // 1. 先判斷客戶意圖
        $this->_intentInfo = $this->determineIntent($client_message);
   
        if($this->_debugMode) {
            echo '<pre>';
            echo '客戶原信息:<br/>';
            print_r('<h2 style="color:red;">'.$client_message.'</h2>');
            print_r($this->_intentInfo);
            echo '</pre>';
        }


        // 3. 根據客戶意圖回答, 如果回復包含額外function, 則需要做第二請求
        $answer = [];
        $log_messages = $this->loadMessages();
        if(!empty($log_messages)) {
            $log_messages = array_slice($log_messages, (($this->_maxRoundsDialogue*2+1)*-1)); // first 5 rounds of dialogue
        }
        $system_message = ['role' => 'system', 'content' => $this->initSystmPrompt($this->_intentInfo)];

        $response = $this->doCurl(array_merge([$system_message], $log_messages), $this->toolFunctions(), 30);
        $first_reply = ($response['choices'][0]['message'] ?? []);
        $this->_extraInfo1 = $first_reply;
        
        if($this->_debugMode && false) {
            echo '<pre>';
            echo 'First Reply:<br/>';
            print_r($first_reply);
            echo '</pre>';
        }
        
        if (isset($first_reply['tool_calls']) && !empty($first_reply['tool_calls'])) {
            $tool_messages = 
            [
                ['role' => 'assistant', 'content' => ($first_reply['content'] ?? ''), 'tool_calls' => $first_reply['tool_calls']]
            ];
            
            foreach ($first_reply['tool_calls'] as $tool_call) {
                $function_name = $tool_call['function']['name'];
                $function_args = json_decode($tool_call['function']['arguments'], true);
                $function_result = [];
                
                switch (strtolower($function_name)) {
                    case 'view_product':
                        $product_name = ($function_args['product_name'] ?? '');
                        if(!empty($product_name)) {
                            $allProducts = MockDatabase::queryAllProducts($product_name);
                            if (!empty($allProducts)) {
                                foreach ($allProducts as $localProduct) {
                                    $function_result['related_products'][] = [
                                        'product_id'    =>  $localProduct['product_id'],
                                        'title'         =>  $localProduct['title'],
                                        'origin'        =>  $localProduct['origin'],
                                        'description'   =>  $localProduct['description'],
                                        'price'         =>  $localProduct['price_currency'] . ' ' . number_format($localProduct['price']) . '/' . $localProduct['price_unit']
                                    ];
                                }
                                $function_result['message'] =  '找到 ' . count($function_result['related_products']) . ' 件相關產品';
                            }
                        }
                        else {
                            $function_result['error_message'] = '我們的商店找不到相關商品。';
                        }
                        break;
                        
                    case 'add_to_cart':
                        $product_name = $function_args['product_name'] ?? '';
                        $quantity = max(1, $function_args['quantity'] ?? 1);
                        if(!empty($product_name) && !empty($quantity)) {
                            $allProducts = MockDatabase::queryAllProducts($product_name);
                            if(!empty($allProducts)) {
                                if(count($allProducts) > 1) {
                                    $function_result = [
                                        'error_message' => '我們的商店，包含多件相關商品，請明確你需要的商品。',
                                        'related_products' => []
                                    ];

                                    foreach ($allProducts as $localProduct) {
                                        $function_result['related_products'][] = [
                                            'product_id'    =>  $localProduct['product_id'],
                                            'title'         =>  $localProduct['title'],
                                            'origin'        =>  $localProduct['origin'],
                                            'description'   =>  $localProduct['description'],
                                            'price'         =>  $localProduct['price_currency'] . ' ' . number_format($localProduct['price']) . '/' . $localProduct['price_unit']
                                        ];
                                    }
                                }
                                else {
                                    $target_product = reset($allProducts);
                                    $shopping_cart = MockDatabase::addToCart($target_product['product_id'], $function_args['quantity']);
                                    if (!empty($shopping_cart)) {
                                        $total_items = 0;
                                        $total_amount = 0;
                                        $shopping_cart_details = '購物車内容:';
                                        $item_count = 1;
                                        foreach ($shopping_cart as $item) {
                                            $total_items += $item['quantity'];
                                            $total_amount += $item['price'] * $item['quantity'];

                                            $shopping_cart_details.= PHP_EOL;
                                            $shopping_cart_details.= $item_count.'. '.$item['title'].' × '. $item['quantity'].' =  HK$'. ($item['price'] * $item['quantity']);
                                            $item_count++;
                                        }
                                        $shopping_cart_details.= PHP_EOL;
                                        $shopping_cart_details.= '總計:'. $total_items. '件商品，HK$'.$total_amount;

                                        $function_result = 
                                        [
                                            'shopping_cart' =>  $shopping_cart,
                                            'message'       =>  $shopping_cart_details,
                                            'total_items'   =>  $total_items,
                                            'total_amount'  =>  $total_amount
                                        ];
                                    }
                                }
                            }
                            else {
                                $function_result['error_message'] = '我們的商店找不到相關商品。';
                            }
                        }
                        break;
                        
                    case 'revise_cart_qty':
                        $product_name = $function_args['product_name'] ?? '';
                        $quantity = max(1, $function_args['quantity'] ?? 1);
                        $target_product = [];
                        
                        if(!empty($product_name) && !empty($quantity)) {
                            $allProducts = MockDatabase::queryAllProducts($product_name);
                            if(!empty($allProducts)) {
                                $shopping_cart = MockDatabase::getCart();
                                if(!empty($shopping_cart)) {
                                    // find target product in current shopping cart
                                    foreach ($allProducts as $localProduct) {
                                        foreach ($shopping_cart as $cart) {
                                            if($localProduct['product_id'] == $cart['product_id']) {
                                                $target_product[] = $localProduct;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        
                        if(!empty($target_product)) {
                            if(count($target_product) > 1) {
                                $function_result = 
                                [
                                    'error_message' => '您的購物車包含多件相關商品，請明確你需要操作的商品。',
                                    'shopping_cart' =>  $shopping_cart,
                                ];
                            }
                            else {
                                $target_product = reset($target_product);
                                $shopping_cart = MockDatabase::reviseCart($target_product['product_id'], $function_args['quantity']);
                                if (empty($shopping_cart)) {
                                    $function_result['error_message'] = '更新購物車不成功，請稍後再嘗試。';
                                }
                                else {
                                    $total_items = 0;
                                    $total_amount = 0;
                                    $shopping_cart_details = '購物車内容:';
                                    $item_count = 1;
                                    foreach ($shopping_cart as $item) {
                                        $total_items += $item['quantity'];
                                        $total_amount += $item['price'] * $item['quantity'];

                                        $shopping_cart_details.= PHP_EOL;
                                        $shopping_cart_details.= $item_count.'. '.$item['title'].' × '. $item['quantity'].' =  HK$'. ($item['price'] * $item['quantity']);
                                        $item_count++;
                                    }
                                    $shopping_cart_details.= PHP_EOL;
                                    $shopping_cart_details.= '總計:'. $total_items. '件商品，HK$'.$total_amount;

                                    $function_result = 
                                    [
                                        'shopping_cart' =>  $shopping_cart,
                                        'message'       =>  $shopping_cart_details,
                                        'total_items'   =>  $total_items,
                                        'total_amount'  =>  $total_amount
                                    ];
                                }
                            }
                        }
                        else {
                            $function_result['error_message'] = '您的購物車内找不到相關商品。';
                        }
                        break;
                        
                    case 'view_cart':
                        $shopping_cart = MockDatabase::getCart();
                        if(empty($shopping_cart)) {
                            $function_result['error_message'] = '您的購物車尚未添加商品';
                        }
                        else {
                            $total_items = 0;
                            $total_amount = 0;
                            $shopping_cart_details = '購物車内容:';
                            $item_count = 1;
                            foreach ($shopping_cart as $item) {
                                $total_items += $item['quantity'];
                                $total_amount += $item['price'] * $item['quantity'];

                                $shopping_cart_details.= PHP_EOL;
                                $shopping_cart_details.= $item_count.'. '.$item['title'].' × '. $item['quantity'].' =  HK$'. ($item['price'] * $item['quantity']);
                                $item_count++;
                            }
                            $shopping_cart_details.= PHP_EOL;
                            $shopping_cart_details.= '總計:'. $total_items. '件商品，HK$'.$total_amount;

                            $function_result = 
                            [
                                'shopping_cart' =>  $shopping_cart,
                                'message'       =>  $shopping_cart_details,
                                'total_items'   =>  $total_items,
                                'total_amount'  =>  $total_amount
                            ];
                        }
                        break;
                        
                    case 'confirm_order':
                        $shopping_cart = MockDatabase::getCart();
                        if(empty($shopping_cart)) {
                            $function_result['error_message'] = '您的購物車尚未添加商品';
                        }
                        else {
                            if(!empty($function_args['customer_name']) && !empty($function_args['customer_phone']) && !empty($function_args['customer_address'])) {
                                $newOrder = MockDatabase::createOrderFromCart([
                                    'name' => $function_args['customer_name'],
                                    'phone' => $function_args['customer_phone'],
                                    'address' => $function_args['customer_address']
                                ]);
                                $function_result['message'] = '訂單已創建！訂單號:'.$newOrder['order_details']['order_id'].'總金額:HK$'.$newOrder['order_details']['grand_total'];
                            }
                            else {
                                $function_result['error_message'] = '請提供你的姓名，電話和地址，以便確認訂單。';
                            }
                        }
                        break;
                        
                    case 'view_order':
                        if(empty($function_args['order_number'])) {
                            $function_result['error_message'] = '請提供你的訂單編號。';
                        }
                        else {
                            $newOrder = MockDatabase::queryOrder($function_args['order_number']);
                            if(!empty($newOrder)) {
                                $function_result = $newOrder;
                            }
                            else {
                                $function_result['error_message'] = '找不到此訂單。';
                            }
                        }
                }
                
                if(empty($function_result)) {
                    $function_result['message'] = '關於您詢問的問題，目前暫無相關資料，敬請見諒。'; 
                }
                
                $tool_messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $tool_call['id'],
                    'content' => json_encode($function_result, JSON_UNESCAPED_UNICODE)
                ];
            }
            
            $this->_extraInfo2 = $tool_messages;
            
            if($this->_debugMode) {
                echo '<pre>';
                echo 'Tool_messages:<br/>';
                print_r($tool_messages);
                echo '</pre>';
            }
            
            // 第二請求
            $second_response = $this->doCurl(array_merge([$system_message], $log_messages, $tool_messages), null, 30);
            $answer = $second_response['choices'][0]['message']['content'] ?? '';
        }
        else if(!empty($first_reply['content'])){
            $answer = $first_reply['content'];
        }
        
        if(empty($answer)) {
            $answer = '關於您詢問的問題，目前暫無相關資料，敬請見諒。';
        }
        
        // Save history log
        $this->saveMessages($answer, 'assistant');

        if($this->_debugMode) {
            echo '<pre>';
            echo 'AI 回復:<br/>';
            print_r('<h2 style="color:blue;">'.$answer.'</h2>');
            echo '</pre>';
            echo '<br/><hr/><br/>';
        }
        else {
            return $answer;
        }
    }
    
    function determineIntent($client_message) {
        $result = ['index' => 7, 'short' => '7|其他', 'products' => '', 'description' => ''];
        
        // Determine intent based on customer information
        if(!empty($client_message)) {
            $allProducts = MockDatabase::queryAllProducts($client_message);
            if (!empty($allProducts)) {
                $client_message = '請問 "'.$client_message.'" 嘅資訊？';
            }

            $log_messages = $this->loadMessages();
            if(!empty($log_messages)) {
                $log_messages = array_slice($log_messages, (($this->_maxRoundsDialogue*2)*-1)); // first 5 rounds of dialogue
            }
     
            $log_messages[] = ['role' => 'user', 'content' => $client_message];
            $log_messages = array_merge([['role' => 'system', 'content' => $this->initSystmPrompt()]], $log_messages);

            $response = $this->doCurl($log_messages, null, 30);
            $intent_reply = ($response['choices'][0]['message']['content'] ?? '7|其他');
            if (preg_match('/([1-5])\|([^\|]+)(?:\|([^\n\r]+))?/', $intent_reply, $m)) {
                $intent_reply = implode('|', array_slice($m, 1));
            } else {
                $intent_reply = '7|其他';
            }

            // Convert to array
            $parts = explode('|', $intent_reply);
            $result['index'] = intval($parts[0] ?? 7);
            $result['short'] = trim($parts[1]);
            $result['products'] = trim($parts[2] ?? ''); 
            $result['description'] = $intent_reply;
            
            // Save history log
            $this->saveMessages($client_message, 'user');
        }

        return $result;
    }
    
    function initSystmPrompt($intent = []) {
        $intent_description = implode(PHP_EOL, 
        [
            '1|查詢商品: 詢問商品功能、價格、規格、庫存。',
            '2|添加商品: 表達購買意願、加入購物車或下單。',
            '3|調整商品: 修改或刪除購物車商品。',
            '4|查閱購物車: 查看購物車商品或總額。',
            '5|確認訂單: 結帳、生成訂單或付款。',
            '6|查閱訂單: 查詢已下訂單詳情或進度。',
            '7|其他: 非交易問題，如營業時間、售後、聯絡方式。'
        ]);
        
        $cases = [
            // view_product - 查詢商品
            [
                '你們沒有{商品A}？',
                '1|查詢商品|商品A'
            ],
            [
                '{商品A}和{商品B}',
                '1|查詢商品|{商品A}#{商品B}'
            ],
            [
                '{商品A}今天價格?',
                '1|查詢商品|{商品A}'
            ],
            [
                '我想購買{商品A}',
                '1|查詢商品|{商品A}'
            ],
            [
                '{商品A}有現貨嗎？',
                '1|查詢商品|{商品A}'
            ],

            // add_to_cart - 添加購物車
            [
                '2件{商品A}',
                '2|添加商品|{商品A}*2'
            ],
            [
                '2件{商品A}和1個{商品B}',
                '2|添加商品|{商品A}*2#{商品B}*1'
            ],
            [
                '加入{商品A}',
                '2|添加商品|{商品A}*1'
            ],
            [
                '{商品A}買三個',
                '2|添加商品|{商品A}*3'
            ],

            // revise_cart - 調整購物車
            [
                '{商品A}要1件就可以',
                '3|調整商品|{商品A}*1'
            ],
            [
                '{商品A}要1件，{商品B}則要2個就可以',
                '3|調整商品|{商品A}*1#{商品B}*2'
            ],
            [
                '不要{商品B}了',
                '3|調整商品|{商品B}*0'
            ],
            [
                '{商品A}改為3個',
                '3|調整商品|{商品A}*3'
            ],
            [
                '刪除{商品B}',
                '3|調整商品|{商品B}*0'
            ],

            // view_cart - 查看購物車
            [
                '我的購物車',
                '4|查閱購物車'
            ],
            [
                '我買了什麼',
                '4|查閱購物車'
            ],
            [
                '購物車內容',
                '4|查閱購物車'
            ],
            [
                '看一下購物車',
                '4|查閱購物車'
            ],

            // confirm_order - 確認訂單
            [
                '結賬',
                '5|確認訂單'
            ],
            [
                '付款',
                '5|確認訂單'
            ],
            [
                '我要結帳',
                '5|確認訂單'
            ],
            [
                '去付款',
                '5|確認訂單'
            ],

            // view_order - 查看訂單
            [
                '我的訂單',
                '6|查閱訂單'
            ],
            [
                '訂單記錄',
                '6|查閱訂單'
            ],
            [
                '歷史訂單',
                '6|查閱訂單'
            ],
            [
                '查一下我的訂單',
                '6|查閱訂單'
            ],

            // others - 其他詢問
            [
                '你們的營業時間?',
                '7|其他'
            ],
            [
                '送貨服務',
                '7|其他'
            ],
            [
                '運費多少錢？',
                '7|其他'
            ],
            [
                '客服電話',
                '7|其他'
            ],
            [
                '退貨政策',
                '7|其他'
            ]
        ];
        $cases_description = [];
        foreach ($cases as $case) {
            $case[0] = '「'.$case[0].'」';
            $cases_description[] = implode(' → ', $case);
        }
        $cases_description = implode(PHP_EOL, $cases_description);
        
        // output
        if(!empty($intent['short'])) {
            $ref_short = PHP_EOL.'[客戶意圖]'.PHP_EOL.$intent['index'].'|'.$intent['short'];
            $ref_products = '';
            if(!empty($intent['products'])) {
                $ref_products = [];
                foreach (explode('#', $intent['products']) as $product) {
                    preg_match('/^(.*)(\*)(\d+)$/i', $product, $match);
                    if(!empty($match)) {
                        $ref_products[] = 'product_name: '.$match[1].' | quantity: '.max(0, $match[3]);
                    }
                    else {
                        $ref_products[] = 'product_name: '.$product;
                    }
                }
            }
            if(!empty($ref_products)) {
                $ref_products = PHP_EOL.PHP_EOL.'[商品清單]'.PHP_EOL.implode(PHP_EOL, $ref_products);
            }
            
            $system_prompt = <<<PROMPT
            你是專業客服助理，協助客戶處理商品查詢、購物車、訂單及一般客服問題。
            
            [操作及回覆規範]
            - 嚴格依據提供的[客戶意圖]，並結合[商品清單]（若適用），來執行對應的操作並進行回覆。
            - 回覆時使用客戶原語言（繁中對繁中，英文對英文）。
            - 所有回覆必須基於既定資料，嚴禁編造任何不存在的資訊。
            - 商品ID {product_id} 僅供內部使用，不顯示給客戶。
            - 若無法回答，請回覆：「關於您詢問的問題，目前暫無相關資料，敬請見諒。」
                    
            {$ref_short}{$ref_products}
            
            如果意圖是：
            - 1|查詢商品 → 呼叫 function「view_product」
            - 2|添加商品 → 呼叫 function「add_to_cart」
            - 3|調整商品 → 呼叫 function「revise_cart」
            - 4|查閱購物車 → 呼叫 function「view_cart」
            - 5|確認訂單 → 呼叫 function「confirm_order」
            - 6|查閱訂單 → 呼叫 function「view_order」
            - 7|其他 → 直接用文字回覆，不呼叫 function
                    
            ** function 如有對應參數（例如 {product_name}, {quantity}），請一併提供。**
                
            [其他資訊]
            - 運費：基本 HK$50，滿 HK$300 免運費
            PROMPT;
        }
        else {
            $system_prompt = <<<PROMPT
            您是一位專門負責意圖識別的專家，根據客戶訊息進行精準分析，然後正確判斷其意圖，並嚴格返回以下格式。
                    
            [意圖選項]
            {$intent_description}

            [回覆規範]
            - 只能[意圖選項]其中一項
            - 格式:「意圖編號|意圖名稱|商品名稱」
            - 僅當訊息明確提及商品名稱時填寫，多個用 # 分隔
            - 不得包含其他文字、符號或表情
            - 無法判斷時，輸出:5|其他

            [範例]
            {$cases_description}

            ** 僅輸出結果，不得包含其他說明或符號。**
            PROMPT; 
        }

        return $system_prompt;
    }

    function toolFunctions() {
        $tools = [];

        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'view_product',
                'description' => '查詢商品功能、價格、規格、庫存。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_name' => [ 'type' => 'string', 'description' => '商品名稱']
                    ],
                ]
            ]
        ];

        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'add_to_cart',
                'description' => '將商品加入購物車。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_name' => [ 'type' => 'string', 'description' => '商品名稱'],
                        'quantity' => ['type' => 'integer', 'description' => '數量', 'minimum' => 1]
                    ],
                    'required' => ['product_name', 'quantity']
                ]
            ]
        ];
        
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'revise_cart_qty',
                'description' => '修改或刪除購物車中的商品數量。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'product_name' => ['type' => 'string', 'description' => '商品名稱'],
                        'quantity' => ['type' => 'integer', 'description' => '數量，0 表示移除', 'minimum' => 0]
                    ],
                    'required' => ['product_name', 'quantity']
                ]
            ]
        ];
        
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'view_cart',
                'description' => '查看購物車商品內容和總額。',
                'parameters' => [
                    'type' => 'object', 
                    'properties' => (object)[]
                ]
            ]
        ];

        $tools[] = [
            'type' => 'function', 
            'function' => [
                'name' => 'confirm_order',
                'description' => '結帳、生成訂單。需要客戶提供姓名、電話和地址。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'customer_name' => ['type' => 'string'],
                        'customer_phone' => ['type' => 'string'], 
                        'customer_address' => ['type' => 'string']
                    ],
                    'required' => ['customer_name', 'customer_phone', 'customer_address']
                ]
            ]
        ];
        
        $tools[] = [
            'type' => 'function',
            'function' => [
                'name' => 'view_order',
                'description' => '查詢已下訂單的詳情和進度。',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'order_number' => [
                            'type' => 'string',
                            'description' => '訂單號碼'
                        ]
                    ],
                    'required' => ['order_number']
                ]
            ]
        ];

        return $tools;
    }
}
