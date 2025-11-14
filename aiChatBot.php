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
    private $_maxRoundsDialogue = 5;

    private $_apiKey = 'sk-';
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
        }
        else {
            $result['feedback'] = nl2br(str_replace(['\r\n', '\n'], PHP_EOL, $this->formatedReply($client_message, $result['feedback'])));
        }
        
        // Save history log
        $this->saveMessages($result['message'], 'user');
        $this->saveMessages($result['feedback'], 'assistant');
        
        return $result;
    }

    // Via curl, call api
    public function doCurl($client_message, $tools = null, $timeout = 30) {
        $data = [
            'model'         =>  $this->_apiModel,
            'messages'      =>  $client_message,
            //'temperature'   =>  0,
            'max_tokens'    =>  1000,
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
        $this->_intentInfo = $this->detectIntent($client_message);
        if(empty($this->_intentInfo)) {
            return '抱歉，我暫時無法理解您的問題。';
        }
   
        if($this->_debugMode) {
            echo '<pre>';
            echo '客戶原信息:<br/>';
            print_r('<h2 style="color:red;">'.$client_message.'</h2>');
            print_r($this->_intentInfo);
            echo '</pre>';
        }
        
        // 2. 根據客戶意圖進行進一步操作
        $answer = [];
        switch ((int)$this->_intentInfo['intent_id']) {
            case 1:
                $answer = $this->viewProducts($this->_intentInfo);
                break;
            case 2:
                $answer = $this->addToCart($this->_intentInfo);
                break;
            case 3:
                $answer = $this->reviseCartQty($this->_intentInfo);
                break;
            case 4:
                $answer = $this->viewCart($this->_intentInfo);
                break;
            case 5:
                $answer = $this->confirmOrder($this->_intentInfo);
                break;
            case 6:
                $answer = $this->viewOrder($this->_intentInfo);
                break;
        }
        
        // 3. 優化輸出的答案
        if(empty($answer)) {
            $answer = '抱歉，我暫時無法理解您的問題。';
        }
        $answer = $this->formatedReply($client_message, $answer);

        // Save history log
        $this->saveMessages($client_message, 'user');
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
    
    function detectIntent($client_message) {
        // Determine intent based on customer information
        if(!empty($client_message)) {
            $systemPrompt = <<<PROMPT
            您是意圖識別專家，負責根據客戶訊息輸出對應的意圖 JSON 結果。

            <意圖選項>: 
            1. 商品查詢: 功能、價格、規格、庫存
            2. 加入購物車: 購買意願、加入購物車
            3. 修改購物車: 刪減或修改商品數量
            4. 查看購物車: 商品清單、總金額
            5. 確認訂單: 結帳/付款(customer_info array，含 name/phone/address，可分步補齊)
            6. 訂單查詢: 訂單詳情、進度
            7. 其他問題: 營業時間、售後服務

            <輸出規則>: 
            1. 模型必須輸出 JSON，不可以有額外文字。
            2. JSON 結構如下: 
               - intent_id (number): 意圖編號 1~7
               - intent_name (string): 意圖名稱
               - items (array，可選): 商品列表，每項可包含 name (商品名稱) 與 qty (數量)
               - order_numbers (array，可選): 訂單編號列表
               - customer_info (array，可選，僅 intent_id=5): 包含以下欄位
                    - name (string，可選)
                    - phone (string，可選)
                    - address (string，可選)
            3. 只能輸出一個意圖。
            4. 若訊息有商品名稱，必填 items。
            5. 若商品有數量，需以 qty 標示。
            6. 多個商品用 items 多筆呈現。
            7. 多個訂單編號用 order_numbers 陣列呈現。
            8. 若無商品或訂單編號，不需提供 items 或 order_numbers。
            9. 對於確認訂單意圖: 
               - 如果使用者尚未提供聯絡資訊，可先輸出空的 customer_info 或缺少欄位的 JSON。
               - 後續使用者補充聯絡資訊時，模型應保持 thread context，將欄位補齊到同一個 intent JSON。
            10. JSON 必須合法，不能有多餘文字。

            <範例>: 
            - 您們沒有商品A？ → {"intent_id":1,"intent_name":"商品查詢","items":[{"name":"商品A"}]}
            - 商品A和商品B" → {"intent_id":1,"intent_name":"商品查詢","items":[{"name":"商品A"},{"name":"商品B"}]}
            - {商品A}有貨嗎？ → {"intent_id":1,"intent_name":"商品查詢","items":[{"name":"商品A"}]}

            - 2件商品A → {"intent_id":2,"intent_name":"加入購物車","items":[{"name":"商品A","qty":2}]}
            - 2件商品A和1個商品B → {"intent_id":2,"intent_name":"加入購物車","items":[{"name":"商品A","qty":2},{"name":"商品B","qty":1}]}
            - 加多1個商品B → {"intent_id":2,"intent_name":"加入購物車","items":[{"name":"商品B","qty":1}]}
                    
            - 商品A要1個就可以 → {"intent_id":3,"intent_name":"調整商品","items":[{"name":"商品A","qty":1}]}
            - 商品A改為1件 → {"intent_id":3,"intent_name":"修改購物車","items":[{"name":"商品A","qty":1}]}
            - 不需要商品B了 → {"intent_id":3,"intent_name":"修改購物車","items":[{"name":"商品B","qty":0}]}

            - 我的購物車 → {"intent_id":4,"intent_name":"查看購物車"}
                    
            - 結賬 → {"intent_id":5,"intent_name":"確認訂單","customer_info":[]}
            - 王小明，12345678，香港中環德輔道中151號" → {"intent_id":5,"intent_name":"確認訂單","customer_info":{"name":"王小明","phone":"12345678","address":"香港中環德輔道中151號"}}
            
            - 我的訂單 → {"intent_id":6,"intent_name":"訂單查詢","order_numbers":[]}
            - 訂單 ABC123 → {"intent_id":6,"intent_name":"訂單查詢","order_numbers":["ABC123"]}
                    
            - 您們的營業時間? → {"intent_id":7,"intent_name":"其他問題"}
            PROMPT;
            
            $allProducts = MockDatabase::queryAllProducts($client_message);
            if (!empty($allProducts)) {
                $client_message = '請問 "'.$client_message.'" 嘅資訊？';
            }

            $log_messages = $this->loadMessages();
            if(!empty($log_messages)) {
                $log_messages = array_slice($log_messages, (($this->_maxRoundsDialogue*2)*-1));
            }
            $log_messages[] = ['role' => 'user', 'content' => $client_message];
            $log_messages = array_merge([['role' => 'system', 'content' => $systemPrompt]], $log_messages);
            
            $response = $this->doCurl($log_messages, null, 30);
            $intent_reply = ($response['choices'][0]['message']['content'] ?? '');
            if(!empty($intent_reply)) {
                $intent_reply = json_decode($intent_reply, true);
                if(!empty($intent_reply['intent_id'])) {
                    return $intent_reply;
                }
            }
        }
        
        return false;
    }
    
    // 商品交易 functions
    function viewProducts($intentInfo) {
        $answer = [];
        
        if(!empty($intentInfo['items'])) {
            foreach ($intentInfo['items'] as $item) {
                $allProducts = MockDatabase::queryAllProducts($item['name']);
                if(!empty($allProducts)) {
                    $related_products = [];
                    foreach ($allProducts as $localProduct) {
                        $related_products[$localProduct['product_id']] = [
                            'product_id'        =>  $localProduct['product_id'],
                            'title'             =>  $localProduct['title'],
                            'origin'            =>  $localProduct['origin'],
                            'description'       =>  $localProduct['description'],
                            'price_currency'    =>  $localProduct['price_currency'],
                            'price'             =>  $localProduct['price'],
                            'price_unit'        =>  $localProduct['price_unit'],
                            'price_description' =>  $localProduct['price_currency'] . ' ' . number_format($localProduct['price']) . '/' . $localProduct['price_unit']
                        ];
                    }
                    $answer[] = PHP_EOL.'#商店有'.count($related_products).'款<'.$item['name'].'>。';
                    foreach ($related_products as $product) {
                        $answer[] = PHP_EOL.implode(PHP_EOL, [
                            '名稱: '.$product['title'],
                            '產地: '.$product['origin'],
                            '描述: '.$product['description'],
                            '價格: '.$product['price_description'],
                        ]);
                    }
                }
                else {
                    $answer[] = PHP_EOL.'#商店找不到<'.$item['name'].'>。';
                }
            }
        }
        
        return $answer;
    }
    
    function addToCart($intentInfo) {
        $answer = [];
        
        if(!empty($intentInfo['items'])) {
            foreach ($intentInfo['items'] as $item) {
                $allProducts = MockDatabase::queryAllProducts($item['name']);
                if(!empty($allProducts)) {
                    $related_products = [];
                    foreach ($allProducts as $localProduct) {
                        $related_products[$localProduct['product_id']] = [
                            'product_id'        =>  $localProduct['product_id'],
                            'title'             =>  $localProduct['title'],
                            'origin'            =>  $localProduct['origin'],
                            'description'       =>  $localProduct['description'],
                            'price_currency'    =>  $localProduct['price_currency'],
                            'price'             =>  $localProduct['price'],
                            'price_unit'        =>  $localProduct['price_unit'],
                            'price_description' =>  $localProduct['price_currency'] . ' ' . number_format($localProduct['price']) . '/' . $localProduct['price_unit']
                        ];
                    }
                    
                    if(count($related_products) > 1) {
                        $answer[] = PHP_EOL.'#商店有'.count($related_products).'款<'.$item['name'].'>，請明確您需要的商品。';
                        foreach ($related_products as $product) {
                            $answer[] = PHP_EOL.implode(PHP_EOL, [
                                '名稱: '.$product['title'],
                                '產地: '.$product['origin'],
                                '描述: '.$product['description'],
                                '價格: '.$product['price_description'],
                            ]);
                        }
                    }
                    else {
                        $target_product = reset($related_products);
                        $shopping_cart = MockDatabase::addToCart($target_product['product_id'], $item['qty']);
                        if (!empty($shopping_cart)) {
                            $answer[] = PHP_EOL.'#<'.$item['name'].'>已成功加到您購物車。';
                        }
                    }
                }
                else {
                    $answer[] = PHP_EOL.'#商店找不到<'.$item['name'].'>。';
                }
            }
            
            // Showing the latest shopping cart contents
            $shopping_cart = MockDatabase::getCart();
            if(!empty($shopping_cart)) {
                $answer[] = PHP_EOL.'#最新購物車内容:';
                
                $total_items = 0;
                $total_amount = 0;
                $item_count = 1;
                foreach ($shopping_cart as $item) {
                    $answer[] = PHP_EOL.$item_count.'. '.$item['title'].' × '. $item['quantity'].' =  HK$'. ($item['price'] * $item['quantity']);
                    $total_items += $item['quantity'];
                    $total_amount += $item['price'] * $item['quantity'];
                    $item_count++;
                }
                
                $shipping_fee = 50;
                if($total_amount >= 300) {
                    $shipping_fee = 0;
                }
                
                $answer[] = PHP_EOL.'共'. $total_items. '件商品，合計金額 HK$'.$total_amount;
                $answer[] = PHP_EOL.'運費 HK$'.$shipping_fee;
                $answer[] = PHP_EOL.'纍計總金額 HK$'.($total_amount + $shipping_fee);
            }
        }
        
        return $answer;
    }
    
    function reviseCartQty($intentInfo) {
        $answer = [];
        
        if(!empty($intentInfo['items'])) {
            
            foreach ($intentInfo['items'] as $item) {
                $allProducts = MockDatabase::queryAllProducts($item['name']);
                if(!empty($allProducts)) {
                    $related_products = [];
                    foreach ($allProducts as $localProduct) {
                        $related_products[$localProduct['product_id']] = [
                            'product_id'        =>  $localProduct['product_id'],
                            'title'             =>  $localProduct['title'],
                            'origin'            =>  $localProduct['origin'],
                            'description'       =>  $localProduct['description'],
                            'price_currency'    =>  $localProduct['price_currency'],
                            'price'             =>  $localProduct['price'],
                            'price_unit'        =>  $localProduct['price_unit'],
                            'price_description' =>  $localProduct['price_currency'] . ' ' . number_format($localProduct['price']) . '/' . $localProduct['price_unit']
                        ];
                    }
                    
                    // find target product in current shopping cart
                    $shopping_cart = MockDatabase::getCart();
                    $target_product = [];
                    foreach ($related_products as $product) {
                        foreach ($shopping_cart as $cart) {
                            if($product['product_id'] == $cart['product_id']) {
                                $target_product[] = $product;
                            }
                        }
                    }
                    
                    if(!empty($target_product)) {
                        if(count($target_product) > 1) {
                            $answer[] = PHP_EOL.'#現在購物車有'.count($target_product).'款<'.$item['name'].'>，請明確您需要操作的商品。';
                        }
                        else {
                            $target_product = reset($target_product);
                            $shopping_cart = MockDatabase::reviseCart($target_product['product_id'], $item['qty']);
                            if(!empty($shopping_cart)) {
                                if(!empty($item['qty'])) {
                                    $answer[] = PHP_EOL.'#購物車内<'.$item['name'].'>的數量已更新。';
                                }
                                else {
                                    $answer[] = PHP_EOL.'#<'.$item['name'].'>已從購物車移除。';
                                }
                            }
                        }
                    }
                    else {
                        $function_result['error_message'] = '#購物車内找不到<'.$item['name'].'>。';
                    }
                }
            }
            
            // Showing the latest shopping cart contents
            $shopping_cart = MockDatabase::getCart();
            if(!empty($shopping_cart)) {
                $answer[] = PHP_EOL.'#最新購物車内容:';
                
                $total_items = 0;
                $total_amount = 0;
                $item_count = 1;
                foreach ($shopping_cart as $item) {
                    $answer[] = PHP_EOL.$item_count.'. '.$item['title'].' × '. $item['quantity'].' =  HK$'. ($item['price'] * $item['quantity']);
                    $total_items += $item['quantity'];
                    $total_amount += $item['price'] * $item['quantity'];
                    $item_count++;
                }
                
                $shipping_fee = 50;
                if($total_amount >= 300) {
                    $shipping_fee = 0;
                }
                
                $answer[] = PHP_EOL.'共'. $total_items. '件商品，合計金額 HK$'.$total_amount;
                $answer[] = PHP_EOL.'運費 HK$'.$shipping_fee;
                $answer[] = PHP_EOL.'纍計總金額 HK$'.($total_amount + $shipping_fee);
            }
        }
        

        return $answer;
    }
    
    function viewCart($intentInfo) {
        $answer = [];
        
        $shopping_cart = MockDatabase::getCart();
        if(!empty($shopping_cart)) {
            $answer[] = PHP_EOL.'#最新購物車内容:';

            $total_items = 0;
            $total_amount = 0;
            $item_count = 1;
            foreach ($shopping_cart as $item) {
                $answer[] = PHP_EOL.$item_count.'. '.$item['title'].' × '. $item['quantity'].' =  HK$'. ($item['price'] * $item['quantity']);
                $total_items += $item['quantity'];
                $total_amount += $item['price'] * $item['quantity'];
                $item_count++;
            }
            
            $shipping_fee = 50;
            if($total_amount >= 300) {
                $shipping_fee = 0;
            }

            $answer[] = PHP_EOL.'共'. $total_items. '件商品，合計金額 HK$'.$total_amount;
            $answer[] = PHP_EOL.'運費 HK$'.$shipping_fee;
            $answer[] = PHP_EOL.'纍計總金額 HK$'.($total_amount + $shipping_fee);
        }
        else {
            $answer[] = '#您的購物車尚未添加商品';
        }
        
        return $answer;
    }
    
    function confirmOrder($intentInfo) {
        $answer = [];
        
        $shopping_cart = MockDatabase::getCart();
        if(empty($shopping_cart)) {
            $answer[] = PHP_EOL.'您的購物車尚未添加商品';
        }
        else {
            if(!empty($intentInfo['customer_info']['name']) && !empty($intentInfo['customer_info']['phone']) && !empty($intentInfo['customer_info']['address'])) {
                $newOrder = MockDatabase::createOrderFromCart([
                    'name' => $intentInfo['customer_info']['name'],
                    'phone' => $intentInfo['customer_info']['phone'],
                    'address' => $intentInfo['customer_info']['address']
                ]);
                $answer[] = PHP_EOL.'#訂單已創建，以下是您訂單資料:';
     
                $answer[] = '訂單編號: '.$newOrder['order_id'];
                $answer[] = '建立時間: '.$newOrder['created_at'];
                $answer[] = '客戶姓名: '.$newOrder['customer_name'];
                $answer[] = '聯絡電話: '.$newOrder['customer_phone'];
                $answer[] = '收件地址: '.$newOrder['customer_address'];

                $answer[] = '包含商品:';
                $item_count = 1;
                foreach ($newOrder['items'] as $item) {
                    $answer[] = PHP_EOL.$item_count.'. '.$item['title'].' × '. $item['quantity'].' =  HK$'. ($item['price'] * $item['quantity']);
                    $item_count++;
                }

                $answer[] = '合計金額: '.$newOrder['items_total'];
                $answer[] = '運費: '.$newOrder['shipping_fee'];
                $answer[] = '纍計總金額: '.$newOrder['grand_total'];
            }
            else {
                $required_field = [];
                if(empty($intentInfo['customer_info']['name'])) {
                    $required_field[] = '姓名';
                }
                if(empty($intentInfo['customer_info']['phone'])) {
                    $required_field[] = '電話';
                }
                if(empty($intentInfo['customer_info']['address'])) {
                    $required_field[] = '地址';
                }
                $answer[] = PHP_EOL.'請提供'. implode('、', $required_field).'，以便確認訂單。';
            }
        }
        
        return $answer;
    }
    
    function viewOrder($intentInfo) {
        $answer = [];
        
        if(empty($intentInfo['order_numbers'])) {
            $answer[] = PHP_EOL.'#請提供您的訂單編號。';
        }
        else {
            foreach ($intentInfo['order_numbers'] as $order_number) {
                $newOrder = MockDatabase::queryOrder($order_number);
                if(!empty($newOrder)) {
                    $answer[] = PHP_EOL.'#訂單編號<'.$order_number.'>:';
                    
                    $answer[] = '建立時間: '.$newOrder['created_at'];
                    $answer[] = '客戶姓名: '.$newOrder['customer_name'];
                    $answer[] = '聯絡電話: '.$newOrder['customer_phone'];
                    $answer[] = '收件地址: '.$newOrder['customer_address'];
    
                    $answer[] = '包含商品:';
                    $item_count = 1;
                    foreach ($newOrder['items'] as $item) {
                        $answer[] = PHP_EOL.$item_count.'. '.$item['title'].' × '. $item['quantity'].' =  HK$'. ($item['price'] * $item['quantity']);
                        $item_count++;
                    }
                    
                    $answer[] = '合計金額: '.$newOrder['items_total'];
                    $answer[] = '運費: '.$newOrder['shipping_fee'];
                    $answer[] = '纍計總金額: '.$newOrder['grand_total'];
                }
                else {
                     $answer[] = PHP_EOL.'#找不到此訂單<'.$order_number.'>。';
                }
            }
        }
        
        return $answer;
    }
    
    function formatedReply($client_message, $answer) {
        if(!empty($client_message) && !empty($answer)) {
            if(is_array($answer)) {
                $answer = implode(PHP_EOL, $answer);
            }
            $answer = trim($answer, PHP_EOL);
            
            $systemPrompt = <<<PROMPT
            角色: 專業、友善且清晰的 AI 客服。

            語言規則(必須遵守):
            1. 回覆語言必須與<客戶訊息>的語言完全一致。
            2. 如果<客戶訊息>使用英文，輸出英文。
            3. 如果<客戶訊息>使用中文(繁/簡)，輸出相同類型的中文。
            4. 絕不可使用與客戶訊息不同的語言。

            任務:
            將提供的<客服回答>潤飾成專業、友善、清晰和簡潔的<客服回覆>。

            備註:
            請直接輸出<客服回覆>，不要多作解釋。
            PROMPT;

            
            $userPrompt = <<<PROMPT
            <客戶訊息>
            {$client_message}
            
            <客服回答>
            {$answer}
            PROMPT;

            $response = $this->doCurl([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ], null, 30);
            
            $formatedAnswer = ($response['choices'][0]['message']['content'] ?? '');
            
            return $formatedAnswer;
        }
        
        return false;
    }
}
