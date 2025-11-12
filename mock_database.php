<?php
// mock_database.php
session_start();

class MockDatabase {
    public static $FAQ_DATABASE = 
    [
        'todays_specials' => 
        [
            'title' => '今日有咩特價水果？',
            'description' => '本週特價水果：\n🍎 日本富士蘋果 原價10元 → 特價8元/個\n🍌 菲律賓香蕉 原價15元 → 特價12元/梳\n🥭 呂宋芒果 原價42元 → 特價35元/磅\n\n滿300元仲可享免費送貨服務！'
        ],
        'business_hours' => 
        [
            'title' => '你哋嘅營業時間係？',
            'description' => '我哋嘅營業時間係：\n星期一至星期日 09:00-21:00\n全年無休，歡迎隨時光臨！'
        ],
        'delivery_Service' => 
        [
            'title' => '係咪有送貨服務？',
            'description' => '我哋提供送貨服務！\n✓ 滿300元免費送貨（港九新界）\n✓ 最快2小時送到指定地址\n✓ 可選擇指定時間配送\n✓ 支援線上付款及貨到付款\n✓ 偏遠地區可能需附加運費'
        ],
        'storage_method' => 
        [
            'title' => '點樣保存水果？',
            'description' => '水果保存小貼士：\n🍌 香蕉、芒果等熱帶水果不宜雪藏，放在陰涼處即可\n🍎 蘋果、橙等可雪櫃保存，保鮮期更長\n🍓 草莓、提子等應盡快食用，雪櫃可保存2-3天\n🥭 未熟水果可放在室溫下催熟，成熟後再雪藏'
        ],
        'address' => 
        [
            'title' => '你哋嘅地址係？',
            'description' => '我哋嘅店舖地址：香港銅鑼灣軒尼詩道123號\n\n附近地標：\n✓ 港鐵銅鑼灣站步行3分鐘\n✓ SOGO百貨對面\n✓ 停車方便，附近有多個停車場'
        ],
        'payment' => 
        [
            'title' => '如何付款？',
            'description' => '我哋接受多種付款方式：\n💵 現金支付\n💳 信用卡（Visa/MasterCard/銀聯）\n📱 移動支付（支付寶/微信支付/八達通）\n🏦 轉帳付款（支援FPS/ATM）'
        ]
    ];
    
    public static $PRODUCT_DATABASE = 
    [
        'japanese_fuji_apple' => 
        [
            'product_id' => 'japanese_fuji_apple',
            'title' => '🍎 日本富士蘋果',
            'origin' => '日本青森縣',
            'description' => '清脆多汁，甜度高，果肉細緻，適合直接食用或製作沙律',
            'price_currency' => 'HK$',
            'price' => 8,
            'price_unit'  => '個',
            'tags' => '日本蘋果, 蘋果'
        ],
        'american_red_apple' => 
        [
            'product_id' => 'american_red_apple',
            'title' => '🍎 美國紅蘋果',
            'origin' => '美國華盛頓州',
            'description' => '果肉清脆多汁，酸甜比例完美，帶有濃郁蘋果香氣。富含膳食纖維和維生素C，是健康美味的日常水果選擇。',
            'price_currency' => 'HK$',
            'price' => 10,
            'price_unit'  => '個',
            'tags' => '美國蘋果, 紅蘋果, 蘋果'
        ],
        'philippine_bananas' => 
        [
            'product_id' => 'philippine_bananas',
            'title' => '🍌 菲律賓香蕉',
            'origin' => '菲律賓產地',
            'description' => '香氣濃郁，營養豐富，口感綿密，富含鉀質',
            'price_currency' => 'HK$',
            'price' => 12,
            'price_unit'  => '梳',
            'tags' => '香蕉'
        ],
        'australian_orange' => 
        [
            'product_id' => 'australian_orange',
            'title' => '🍊 澳洲橙',
            'origin' => '澳洲',
            'description' => '汁多味美，維生素C豐富，甜中帶微酸，增強免疫力',
            'price_currency' => 'HK$',
            'price' => 28,
            'price_unit'  => '磅',
            'tags' => '橙'
        ],
        'korean_strawberries' => 
        [
            'product_id' => 'korean_strawberries',
            'title' => '🍓 韓國草莓',
            'origin' => '韓國產地',
            'description' => '鮮紅飽滿，香氣濃郁，甜中帶酸，尺寸均勻',
            'price_currency' => 'HK$',
            'price' => 68,
            'price_unit'  => '盒',
            'tags' => '草莓, 士多啤梨'
        ],
        'chilean_grapes' => 
        [
            'product_id' => 'chilean_grapes',
            'title' => '🍇 智利提子',
            'origin' => '智利',
            'description' => '果粒飽滿，皮薄多汁，甜度高，無核品種',
            'price_currency' => 'HK$',
            'price' => 42,
            'price_unit'  => '磅',
            'tags' => '提子, 葡萄'
        ],
        'taiwanese_watermelon' => 
        [
            'product_id' => 'taiwanese_watermelon',
            'title' => '🍉 台灣西瓜',
            'origin' => '台灣產地',
            'description' => '皮薄肉紅，清脆多汁，甜度適中，夏季消暑首選',
            'price_currency' => 'HK$',
            'price' => 48,
            'price_unit'  => '個',
            'tags' => '西瓜'
        ],
        'luzon_mango' => 
        [
            'product_id' => 'luzon_mango',
            'title' => '🥭 呂宋芒果',
            'origin' => '菲律賓',
            'description' => '果肉細緻，香氣濃郁，甜度高，適合製作甜品',
            'price_currency' => 'HK$',
            'price' => 35,
            'price_unit'  => '磅',
            'tags' => '芒果'
        ],
    ];

    public static $ORDER_DATABASE = [
        "12345" => [
            "order_id" => "12345",
            "customer_name" => "張三",
            "status" => "已發貨",
            "tracking_number" => "SF1234567890",
            "estimated_delivery" => "2023-10-27",
            "items" => ["iPhone 15 Pro 256GB"],
            "total_amount" => 8999
        ],
        "67890" => [
            "order_id" => "67890",
            "customer_name" => "李四",
            "status" => "處理中",
            "tracking_number" => null,
            "estimated_delivery" => "待定",
            "items" => ["AirPods Pro"],
            "total_amount" => 1799
        ]
    ];
     
    public static function queryFAQ($faq_id) {
        return self::$FAQ_DATABASE[$faq_id] ?? '';
    }

    public static function queryAllProducts($keywords = '') {
        if(!empty($keywords)) {
            $match_products = [];
            $search_terms = explode('#', strtolower(trim($keywords)));

            foreach (self::$PRODUCT_DATABASE as $product) {
                $title = strtolower($product['title']);
                $tags = isset($product['tags']) ? strtolower($product['tags']) : '';

                $match_score = 0;
                foreach ($search_terms as $term) {
                    $term = trim($term);
                    $term = preg_replace('/(\*\d+)$/', '', $term);
                    
                    if (!empty($term)) {
                        // 在title中搜索
                        if (strpos($title, $term) !== false) {
                            $match_score += 2; // title匹配權重更高
                        }
                        // 在tags中搜索
                        if (strpos($tags, $term) !== false) {
                            $match_score += 1;
                        }
                    }
                }

                // 如果有任何搜索詞匹配，就加入結果
                if ($match_score > 0) {
                    $product['match_score'] = $match_score;
                    $match_products[] = $product;
                }
            }

            // 按匹配分數降序排列
            usort($match_products, function($a, $b) {
                return $b['match_score'] - $a['match_score'];
            });

            // 移除臨時的match_score字段
            foreach ($match_products as &$product) {
                unset($product['match_score']);
            }

            return $match_products;
        }
        else {
            return self::$PRODUCT_DATABASE;
        }
    }
    
    public static function queryProducts($product_id) {
        return self::$PRODUCT_DATABASE[$product_id] ?? '';
    }
    
    public static function queryFAQEmbeding($txt) {
        
        
    }
    
    public static function queryProductsEmbeding($txt) {
        
        
    }

    public static function getCart() {
        $session_id = session_id();
        return $_SESSION['shopping_carts'][$session_id] ?? [];
    }

    public static function clearCart() {
        $session_id = session_id();
        $_SESSION['shopping_carts'][$session_id] = [];
        return ["success" => true, "message" => "購物車已清空"];
    }
     
    public static function addToCart($product_id, $quantity) {
        $product = self::$PRODUCT_DATABASE[$product_id] ?? null;
        if(!empty($product)) {
            $session_id = session_id();
            if (!isset($_SESSION['shopping_carts'][$session_id])) {
                $_SESSION['shopping_carts'][$session_id] = [];
            }

            $cart = &$_SESSION['shopping_carts'][$session_id];

            // 檢查是否已存在相同產品
            $existing_index = null;
            foreach ($cart as $index => $item) {
                if ($item['product_id'] === $product_id) {
                    $existing_index = $index;
                    break;
                }
            }

            if ($existing_index !== null) {
                // 更新數量
                $cart[$existing_index]['quantity'] += $quantity;
            } else {
                // 添加新項目
                $cart[] = [
                    'product_id' => $product_id,
                    'title' => $product['title'],
                    'price_currency' => $product['price_currency'],
                    'price' => $product['price'],
                    'price_unit' => $product['price_unit'],
                    'quantity' => $quantity
                ];
            }
            
            $_SESSION['shopping_carts'][$session_id] = $cart;

            return $cart;
        }
        
        return false;
    }

    public static function reviseCart($product_id, $quantity = 0) {
        $session_id = session_id();

        // 检查购物车是否存在
        if (isset($_SESSION['shopping_carts'][$session_id])) {
            $cart = $_SESSION['shopping_carts'][$session_id];

            // 如果数量 <= 0，移除商品
            if ($quantity <= 0) {
                $cart = array_filter($cart, function($item) use ($product_id) {
                    return $item['product_id'] !== $product_id;
                });
                $cart = array_values($cart); // 重新索引数组
            } else {
                // 否则修改商品数量
                foreach ($cart as &$item) {
                    if ($item['product_id'] === $product_id) {
                        $item['quantity'] = $quantity;
                        break;
                    }
                }
            }
            
            $_SESSION['shopping_carts'][$session_id] = $cart;

            return $cart;
        }

        return false;
    }
    
    public static function createOrderFromCart($customer_info) {
        $session_id = session_id();
        $cart = $_SESSION['shopping_carts'][$session_id] ?? [];
        if(!empty($cart)) {
            // 生成訂單ID
            $new_order_id = 68452;
            
            // 計算總金額
            $total_amount = 0;
            foreach ($cart as $item) {
                $total_amount += $item['price'] * $item['quantity'];
            }
            
            $shipping_fee = 50;
            if($total_amount >= 300) {
                $shipping_fee = 0;
            }

            // 創建訂單
            $_SESSION['order_database'][$new_order_id] = [
                'order_id' => $new_order_id,
                'session_id' => $session_id, // 关联 Session ID
                'customer_name' => $customer_info['name'],
                'customer_phone' => $customer_info['phone'],
                'customer_email' => $customer_info['email'] ?? '',
                'items' => $cart,
                'items_total' => $total_amount,
                'shipping_fee' => $shipping_fee,
                'grand_total' => $total_amount + $shipping_fee,
                'status' => '待確認',
                'created_at' => date('Y-m-d H:i:s'),
                'shipping_address' => $customer_info['address']
            ];
            
            if (isset($_SESSION['shopping_carts'][$session_id])) {
                $_SESSION['shopping_carts'][$session_id] = [];
            }
            
            return [
                'order_id' => $new_order_id,
                'order_details' => $_SESSION['order_database'][$new_order_id],
                'requires_confirmation' => true
            ];
        }
        
        return false;
    }
    
    public static function queryOrder($order_id) {
        if (isset($_SESSION['order_database'][$order_id])) {
            return ($_SESSION['order_database'][$order_id]);
        }
        
        return false;
    }
}
?>
