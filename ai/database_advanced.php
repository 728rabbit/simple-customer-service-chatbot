<?php
// database_advanced.php

class AdvancedDatabase {
    private static $ORDER_DATABASE = [];
    private static $PRODUCT_DATABASE = [
        "iphone15" => [
            "product_id" => "iphone15",
            "name" => "iPhone 15 Pro",
            "price" => 7999,
            "stock" => 50,
            "category" => "手機"
        ],
        "airpods" => [
            "product_id" => "airpods", 
            "name" => "AirPods Pro",
            "price" => 1799,
            "stock" => 100,
            "category" => "耳機"
        ],
        "macbook" => [
            "product_id" => "macbook",
            "name" => "MacBook Pro", 
            "price" => 12999,
            "stock" => 20,
            "category" => "電腦"
        ],
        "ipad" => [
            "product_id" => "ipad",
            "name" => "iPad Air",
            "price" => 4799,
            "stock" => 30,
            "category" => "平板"
        ],
        "watch" => [
            "product_id" => "watch",
            "name" => "Apple Watch",
            "price" => 2999,
            "stock" => 40,
            "category" => "手錶"
        ]
    ];
    
    // 購物車系統
    private static $SHOPPING_CARTS = [];
    
    public static function init() {
        // 初始化測試訂單
        self::$ORDER_DATABASE = [
            "12345" => [
                "order_id" => "12345",
                "customer_name" => "張三",
                "customer_phone" => "13800138000",
                "status" => "已發貨",
                "tracking_number" => "SF1234567890",
                "estimated_delivery" => "2024-01-15",
                "items" => [
                    ["product_id" => "iphone15", "name" => "iPhone 15 Pro", "quantity" => 1, "price" => 7999],
                    ["product_id" => "airpods", "name" => "AirPods Pro", "quantity" => 2, "price" => 1799]
                ],
                "total_amount" => 11597,
                "created_at" => "2024-01-10",
                "shipping_address" => "北京市朝陽區建國門外大街1號"
            ]
        ];
    }
    
    /**
     * 添加到購物車
     */
    public static function addToCart($session_id, $product_id, $quantity) {
        if (!isset(self::$PRODUCT_DATABASE[$product_id])) {
            return ["error" => "產品不存在"];
        }
        
        $product = self::$PRODUCT_DATABASE[$product_id];
        
        if ($product['stock'] < $quantity) {
            return ["error" => "庫存不足，目前僅剩 {$product['stock']} 件"];
        }
        
        if (!isset(self::$SHOPPING_CARTS[$session_id])) {
            self::$SHOPPING_CARTS[$session_id] = [];
        }
        
        // 檢查是否已存在相同產品
        $existing_index = null;
        foreach (self::$SHOPPING_CARTS[$session_id] as $index => $item) {
            if ($item['product_id'] === $product_id) {
                $existing_index = $index;
                break;
            }
        }
        
        if ($existing_index !== null) {
            // 更新數量
            self::$SHOPPING_CARTS[$session_id][$existing_index]['quantity'] += $quantity;
        } else {
            // 添加新項目
            self::$SHOPPING_CARTS[$session_id][] = [
                'product_id' => $product_id,
                'name' => $product['name'],
                'price' => $product['price'],
                'quantity' => $quantity,
                'subtotal' => $product['price'] * $quantity
            ];
        }
        
        return [
            "success" => true,
            "message" => "已添加到購物車",
            "cart" => self::$SHOPPING_CARTS[$session_id]
        ];
    }
    
    /**
     * 獲取購物車內容
     */
    public static function getCart($session_id) {
        return self::$SHOPPING_CARTS[$session_id] ?? [];
    }
    
    /**
     * 清空購物車
     */
    public static function clearCart($session_id) {
        self::$SHOPPING_CARTS[$session_id] = [];
        return ["success" => true, "message" => "購物車已清空"];
    }
    
    /**
     * 從購物車移除商品
     */
    public static function removeFromCart($session_id, $product_id) {
        if (!isset(self::$SHOPPING_CARTS[$session_id])) {
            return ["error" => "購物車為空"];
        }
        
        self::$SHOPPING_CARTS[$session_id] = array_filter(
            self::$SHOPPING_CARTS[$session_id],
            function($item) use ($product_id) {
                return $item['product_id'] !== $product_id;
            }
        );
        
        return [
            "success" => true,
            "message" => "商品已移除",
            "cart" => self::$SHOPPING_CARTS[$session_id]
        ];
    }
    
    /**
     * 創建訂單（從購物車）
     */
    public static function createOrderFromCart($session_id, $customer_info) {
        $cart = self::$SHOPPING_CARTS[$session_id] ?? [];
        
        if (empty($cart)) {
            return ["error" => "購物車為空"];
        }
        
        // 檢查庫存
        foreach ($cart as $item) {
            $product = self::$PRODUCT_DATABASE[$item['product_id']];
            if ($product['stock'] < $item['quantity']) {
                return ["error" => "{$product['name']} 庫存不足，目前僅剩 {$product['stock']} 件"];
            }
        }
        
        // 生成訂單ID
        $new_order_id = str_pad(rand(10000, 99999), 5, '0', STR_PAD_LEFT);
        
        // 計算總金額
        $total_amount = 0;
        foreach ($cart as $item) {
            $total_amount += $item['subtotal'];
        }
        
        // 創建訂單
        self::$ORDER_DATABASE[$new_order_id] = [
            "order_id" => $new_order_id,
            "customer_name" => $customer_info['name'],
            "customer_phone" => $customer_info['phone'],
            "customer_address" => $customer_info['address'],
            "items" => $cart,
            "total_amount" => $total_amount,
            "status" => "待確認", // 新狀態：等待客戶確認
            "created_at" => date('Y-m-d H:i:s'),
            "shipping_address" => $customer_info['address']
        ];
        
        // 預扣庫存（確認後才正式扣除）
        foreach ($cart as $item) {
            // 這裡只是預扣，確認後才正式更新庫存
        }
        
        // 不清空購物車，等待確認
        return [
            "success" => true,
            "order_id" => $new_order_id,
            "order_details" => self::$ORDER_DATABASE[$new_order_id],
            "requires_confirmation" => true
        ];
    }
    
    /**
     * 確認訂單
     */
    public static function confirmOrder($order_id) {
        if (!isset(self::$ORDER_DATABASE[$order_id])) {
            return ["error" => "訂單不存在"];
        }
        
        $order = self::$ORDER_DATABASE[$order_id];
        
        if ($order['status'] !== '待確認') {
            return ["error" => "訂單狀態不正確"];
        }
        
        // 正式扣除庫存
        foreach ($order['items'] as $item) {
            self::$PRODUCT_DATABASE[$item['product_id']]['stock'] -= $item['quantity'];
        }
        
        // 更新訂單狀態
        self::$ORDER_DATABASE[$order_id]['status'] = '已確認';
        self::$ORDER_DATABASE[$order_id]['confirmed_at'] = date('Y-m-d H:i:s');
        
        // 清空對應會話的購物車
        // 這裡需要將會話與訂單關聯，簡化處理
        
        return [
            "success" => true,
            "message" => "訂單確認成功",
            "order" => self::$ORDER_DATABASE[$order_id]
        ];
    }
    
    /**
     * 取消訂單
     */
    public static function cancelOrder($order_id) {
        if (!isset(self::$ORDER_DATABASE[$order_id])) {
            return ["error" => "訂單不存在"];
        }
        
        // 移除訂單
        unset(self::$ORDER_DATABASE[$order_id]);
        
        return [
            "success" => true,
            "message" => "訂單已取消"
        ];
    }
    
    /**
     * 查詢訂單
     */
    public static function queryOrder($order_id) {
        return self::$ORDER_DATABASE[$order_id] ?? ["error" => "未找到訂單號 {$order_id} 的資訊"];
    }
    
    /**
     * 獲取產品列表
     */
    public static function getProducts() {
        return self::$PRODUCT_DATABASE;
    }
    
    /**
     * 獲取待確認的訂單
     */
    public static function getPendingOrders($session_id) {
        // 簡化：返回所有待確認訂單
        $pending_orders = [];
        foreach (self::$ORDER_DATABASE as $order) {
            if ($order['status'] === '待確認') {
                $pending_orders[] = $order;
            }
        }
        return $pending_orders;
    }
}

// 初始化數據庫
AdvancedDatabase::init();
?>
