<?php
// rag_engine.php

class RAGEngine {
    private $vector_db_path;
    
    public function __construct($db_path = 'knowledge_base/vectors/') {
        $this->vector_db_path = $db_path;
        if (!is_dir($this->vector_db_path)) {
            mkdir($this->vector_db_path, 0755, true);
        }
        $this->initializeKnowledgeBase();
    }
    
    private function initializeKnowledgeBase() {
        // 如果知識庫已初始化，則跳過
        if (count(glob($this->vector_db_path . '*.json')) > 0) {
            return;
        }
        
        // 退貨政策
        $this->addDocument('return_policy', "
        【退貨政策詳細說明】
        1. 退貨時限：商品到貨後7天內可申請無理由退貨，30天內可申請質量問題退貨
        2. 商品條件：商品必須保持完好，原包裝、配件、標籤齊全，不影響二次銷售
        3. 特殊商品：數碼產品一經激活不支持7天無理由退貨，但質量問題除外
        4. 退貨流程：在線提交退貨申請 → 客服審核 → 預約取件 → 商品檢測 → 退款處理
        5. 運費說明：無理由退貨運費由買家承擔，質量問題退貨由賣家承擔
        6. 退款時效：收到退貨並檢測無誤後，3-5個工作日內原路退回款項
        7. 聯繫方式：退貨問題可聯繫客服熱線 400-123-4567
        ", ['type' => 'policy', 'category' => 'returns', 'importance' => 'high']);
        
        // 物流政策
        $this->addDocument('shipping_policy', "
        【物流配送政策】
        1. 配送範圍：全國包郵（新疆、西藏、港澳台地區需額外付費）
        2. 配送時效：
           - 一線城市：1-2個工作日
           - 省會城市：2-3個工作日  
           - 地級市：3-4個工作日
           - 縣級及以下：4-5個工作日
        3. 快遞公司：默認發順豐速運，可選圓通、申通、中通
        4. 物流跟踪：訂單發貨後系統自動發送短信通知運單號
        5. 配送費用：滿99元包郵，不滿99元收取10元運費
        6. 特殊時段：節假日期間配送可能延遲1-2天
        ", ['type' => 'policy', 'category' => 'shipping', 'importance' => 'high']);
        
        // 產品信息
        $this->addDocument('product_iphone15', "
        【iPhone 15 Pro 詳細規格】
        - 顯示屏：6.1英寸超視網膜XDR顯示屏，支持ProMotion自適應刷新率
        - 處理器：A17 Pro芯片，6核CPU，6核GPU，16核神經網絡引擎
        - 相機系統：
          • 主攝：4800萬像素，ƒ/1.78光圈，傳感器位移式光學圖像防抖
          • 超廣角：1200萬像素，ƒ/2.2光圈，120°視角
          • 長焦：1200萬像素，3倍光學變焦
        - 儲存容量：128GB / 256GB / 512GB / 1TB
        - 顏色選擇：黑色鈦金屬、白色鈦金屬、藍色鈦金屬、自然鈦金屬
        - 價格信息：
          • 128GB：7999元
          • 256GB：8999元  
          • 512GB：10999元
          • 1TB：12999元
        - 保修服務：1年官方硬件保修，90天免費電話技術支持
        - 庫存狀態：現貨充足，下單後24小時內發貨
        ", ['type' => 'product', 'category' => 'iphone', 'importance' => 'high']);
        
        // 保修政策
        $this->addDocument('warranty_policy', "
        【產品保修政策】
        1. 保修期限：所有產品享受1年全國聯保，從購買日期開始計算
        2. 保修範圍：正常使用情況下出现的硬件故障
        3. 非保修範圍：
           - 人為損壞（摔落、進液、私自拆修）
           - 自然損耗（電池容量隨使用時間下降）
           - 不可抗力因素（火災、水災、地震等）
           - 軟件問題（可通過恢復出廠設置解決）
        4. 保修流程：聯繫客服 → 故障診斷 → 寄送維修 → 檢測維修 → 寄回用戶
        5. 維修時效：一般情況7-15個工作日，緊急情況可申請加急處理
        6. 延保服務：可購買延長保修服務，最長延長至3年
        ", ['type' => 'policy', 'category' => 'warranty', 'importance' => 'high']);
    }
    
    private function getTextVector($text) {
        $words = preg_split('/\s+/', mb_strtolower($text));
        $wordFreq = array_count_values($words);
        
        $vector = array_fill(0, 100, 0);
        foreach ($wordFreq as $word => $freq) {
            $hash = crc32($word) % 100;
            $vector[$hash] += $freq;
        }
        
        $norm = sqrt(array_sum(array_map(function($x) { return $x * $x; }, $vector)));
        if ($norm > 0) {
            $vector = array_map(function($x) use ($norm) { return $x / $norm; }, $vector);
        }
        
        return $vector;
    }
    
    private function cosineSimilarity($vec1, $vec2) {
        $dotProduct = 0;
        for ($i = 0; $i < count($vec1); $i++) {
            $dotProduct += $vec1[$i] * $vec2[$i];
        }
        return $dotProduct;
    }
    
    public function addDocument($doc_id, $content, $metadata = []) {
        $chunks = $this->splitText($content);
        
        foreach ($chunks as $chunk_id => $chunk) {
            $vector = $this->getTextVector($chunk);
            
            $doc_entry = [
                'id' => $doc_id . '_' . $chunk_id,
                'content' => $chunk,
                'vector' => $vector,
                'metadata' => $metadata
            ];
            
            file_put_contents(
                $this->vector_db_path . $doc_entry['id'] . '.json',
                json_encode($doc_entry, JSON_UNESCAPED_UNICODE)
            );
        }
    }
    
    private function splitText($text, $chunk_size = 500) {
        $sentences = preg_split('/(?<=[。！？\.!?])/u', $text);
        $chunks = [];
        $current_chunk = '';
        
        foreach ($sentences as $sentence) {
            if (mb_strlen($current_chunk . $sentence) < $chunk_size) {
                $current_chunk .= $sentence;
            } else {
                if (!empty($current_chunk)) {
                    $chunks[] = $current_chunk;
                }
                $current_chunk = $sentence;
            }
        }
        
        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }
        
        return $chunks;
    }
    
    public function search($query, $top_k = 3) {
        $query_vector = $this->getTextVector($query);
        $results = [];
        
        $files = glob($this->vector_db_path . '*.json');
        
        foreach ($files as $file) {
            $doc_data = json_decode(file_get_contents($file), true);
            $similarity = $this->cosineSimilarity($query_vector, $doc_data['vector']);
            
            $results[] = [
                'content' => $doc_data['content'],
                'similarity' => $similarity,
                'metadata' => $doc_data['metadata']
            ];
        }
        
        usort($results, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        return array_slice($results, 0, $top_k);
    }
}
?>
