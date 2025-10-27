<?php
// index.php
header('Content-Type: text/html; charset=utf-8');

// 簡單的路由
if (isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    
    if ($_GET['action'] == 'chat') {
        require_once 'openai_client.php';
        require_once 'rag_engine.php';
        require_once 'database.php';
        require_once 'session_manager.php';
        
        handleChat();
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>智能客服系統 - 完整版</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #2c3e50, #34495e);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 1.1em;
        }
        
        .chat-container {
            display: flex;
            height: 600px;
        }
        
        .sidebar {
            width: 300px;
            background: #f8f9fa;
            border-right: 1px solid #e9ecef;
            padding: 20px;
            overflow-y: auto;
        }
        
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .chat-messages {
            flex: 1;
            padding: 20px;
            overflow-y: auto;
            background: #fff;
        }
        
        .message {
            margin-bottom: 20px;
            max-width: 80%;
            animation: fadeIn 0.3s ease-in;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .user-message {
            margin-left: auto;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 12px 18px;
            border-radius: 18px 18px 5px 18px;
        }
        
        .assistant-message {
            background: #f1f3f5;
            padding: 12px 18px;
            border-radius: 18px 18px 18px 5px;
            border: 1px solid #e9ecef;
        }
        
        .message-meta {
            font-size: 0.8em;
            opacity: 0.7;
            margin-top: 5px;
        }
        
        .input-area {
            padding: 20px;
            border-top: 1px solid #e9ecef;
            background: #f8f9fa;
        }
        
        .input-group {
            display: flex;
            gap: 10px;
        }
        
        .input-group input {
            flex: 1;
            padding: 12px 16px;
            border: 2px solid #e9ecef;
            border-radius: 25px;
            font-size: 16px;
            outline: none;
            transition: border-color 0.3s;
        }
        
        .input-group input:focus {
            border-color: #667eea;
        }
        
        .input-group button {
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            transition: transform 0.2s;
        }
        
        .input-group button:hover {
            transform: translateY(-2px);
        }
        
        .quick-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 15px;
        }
        
        .quick-btn {
            padding: 8px 12px;
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 15px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .quick-btn:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .system-indicator {
            display: inline-block;
            padding: 2px 8px;
            background: #28a745;
            color: white;
            border-radius: 10px;
            font-size: 0.7em;
            margin-left: 8px;
        }
        
        .typing-indicator {
            display: inline-flex;
            align-items: center;
            color: #6c757d;
            font-style: italic;
        }
        
        .typing-dots {
            display: inline-flex;
            margin-left: 5px;
        }
        
        .typing-dots span {
            animation: typing 1.4s infinite;
            margin: 0 1px;
        }
        
        .typing-dots span:nth-child(2) { animation-delay: 0.2s; }
        .typing-dots span:nth-child(3) { animation-delay: 0.4s; }
        
        @keyframes typing {
            0%, 60%, 100% { opacity: 0.3; }
            30% { opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🤖 智能客服系統</h1>
            <p>整合 RAG + 函數調用 + 會話管理 | 完整企業級解決方案</p>
        </div>
        
        <div class="chat-container">
            <div class="sidebar">
                <h3>💡 快速操作</h3>
                <div class="quick-actions">
                    <div class="quick-btn" onclick="setQuickAction('查訂單 12345 的狀態')">查訂單狀態</div>
                    <div class="quick-btn" onclick="setQuickAction('我要購買 iPhone 15')">購買產品</div>
                    <div class="quick-btn" onclick="setQuickAction('退貨政策是什麼？')">退貨政策</div>
                    <div class="quick-btn" onclick="setQuickAction('物流配送時間多久？')">物流查詢</div>
                    <div class="quick-btn" onclick="setQuickAction('有哪些產品可以買？')">產品列表</div>
                    <div class="quick-btn" onclick="setQuickAction('如何申請售後？')">售後指南</div>
                </div>
                
                <h3 style="margin-top: 30px;">📊 系統狀態</h3>
                <div id="systemStatus">
                    <div>🟢 RAG 引擎: 就緒</div>
                    <div>🟢 函數調用: 就緒</div>
                    <div>🟢 會話管理: 就緒</div>
                </div>
                
                <h3 style="margin-top: 30px;">🔧 功能特性</h3>
                <ul style="font-size: 0.9em; color: #666; line-height: 1.6;">
                    <li>智能意圖識別</li>
                    <li>實時知識檢索</li>
                    <li>多輪對話記憶</li>
                    <li>訂單操作集成</li>
                    <li>政策文檔查詢</li>
                </ul>
            </div>
            
            <div class="chat-area">
                <div class="chat-messages" id="chatMessages">
                    <div class="message assistant-message">
                        <strong>👋 歡迎使用智能客服系統！</strong><br><br>
                        我可以幫您：<br>
                        • 📦 <strong>查詢訂單狀態</strong> - 實時跟蹤物流信息<br>
                        • 🛒 <strong>創建新訂單</strong> - 快速下單購買產品<br>
                        • 📚 <strong>解答政策問題</strong> - 基於最新文檔提供準確答案<br>
                        • 🔍 <strong>產品信息查詢</strong> - 詳細規格和價格<br><br>
                        請告訴我您需要什麼幫助！
                    </div>
                </div>
                
                <div class="input-area">
                    <div class="input-group">
                        <input type="text" id="messageInput" placeholder="輸入您的問題..." onkeypress="handleKeyPress(event)">
                        <button onclick="sendMessage()">發送</button>
                    </div>
                    <div class="quick-actions">
                        <div class="quick-btn" onclick="setQuickAction('我的訂單 12345 到哪了？')">訂單查詢</div>
                        <div class="quick-btn" onclick="setQuickAction('我想買 2 台 iPhone 15 Pro')">快速下單</div>
                        <div class="quick-btn" onclick="setQuickAction('保修政策是多久？')">保修查詢</div>
                        <div class="quick-btn" onclick="clearChat()">清空對話</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let sessionId = localStorage.getItem('sessionId') || generateSessionId();
        localStorage.setItem('sessionId', sessionId);
        
        function generateSessionId() {
            return 'session_' + Math.random().toString(36).substr(2, 9);
        }
        
        function setQuickAction(text) {
            document.getElementById('messageInput').value = text;
        }
        
        function handleKeyPress(event) {
            if (event.key === 'Enter') {
                sendMessage();
            }
        }
        
        async function sendMessage() {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();
            const chatMessages = document.getElementById('chatMessages');
            
            if (!message) return;
            
            // 添加用戶消息
            addMessage(message, 'user');
            input.value = '';
            
            // 顯示輸入中指示
            const typingIndicator = addTypingIndicator();
            
            try {
                const response = await fetch('?action=chat', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        message: message,
                        session_id: sessionId
                    })
                });
                
                const data = await response.json();
                
                // 移除輸入中指示
                typingIndicator.remove();
                
                if (data.success) {
                    let reply = data.reply;
                    if (data.used_rag) {
                        reply += ' <span class="system-indicator">RAG</span>';
                    }
                    if (data.used_function) {
                        reply += ' <span class="system-indicator">函數調用</span>';
                    }
                    addMessage(reply, 'assistant');
                } else {
                    addMessage('❌ ' + data.reply, 'assistant');
                }
            } catch (error) {
                typingIndicator.remove();
                addMessage('❌ 網絡錯誤：' + error.message, 'assistant');
            }
        }
        
        function addMessage(content, role) {
            const chatMessages = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${role}-message`;
            messageDiv.innerHTML = content;
            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        
        function addTypingIndicator() {
            const chatMessages = document.getElementById('chatMessages');
            const typingDiv = document.createElement('div');
            typingDiv.className = 'message assistant-message typing-indicator';
            typingDiv.innerHTML = 'AI 正在思考中<span class="typing-dots"><span>.</span><span>.</span><span>.</span></span>';
            chatMessages.appendChild(typingDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            return typingDiv;
        }
        
        function clearChat() {
            if (confirm('確定要清空對話記錄嗎？')) {
                document.getElementById('chatMessages').innerHTML = `
                    <div class="message assistant-message">
                        <strong>👋 歡迎使用智能客服系統！</strong><br><br>
                        對話記錄已清空，請問有什麼可以幫您？
                    </div>
                `;
                sessionId = generateSessionId();
                localStorage.setItem('sessionId', sessionId);
            }
        }
        
        // 聚焦輸入框
        document.getElementById('messageInput').focus();
    </script>
</body>
</html>
