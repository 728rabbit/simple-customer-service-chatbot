<?php
// openai_client.php

class OpenAIClient {
    private $api_key;
    private $api_url = 'https://api.chatanywhere.tech/v1/chat/completions';

    public function __construct($api_key) {
        $this->api_key = $api_key;
    }
    
    public function chat($messages, $tools = null, $timeout = 60) {
        $data = [
            'model' => 'deepseek-chat', // DeepSeek 模型名稱
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 1000,
            'stream' => false
        ];
        
        
        /*$data = [
            'model' => 'gpt-3.5-turbo-1106',
            'messages' => $messages,
            'temperature' => 0.7,
            'max_tokens' => 1000
        ];*/
        
        if ($tools) {
            $data['tools'] = $tools;
            $data['tool_choice'] = 'auto';
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
            CURLOPT_CONNECTTIMEOUT => 10,
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
?>
