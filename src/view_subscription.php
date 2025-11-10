<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>مشاهده مشخصات اکانت</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .container {
            background: white;
            border-radius: 15px;
            padding: 30px;
            max-width: 500px;
            width: 100%;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            text-align: center;
        }
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .subscription-link {
            background: #f5f5f5;
            border: 2px dashed #667eea;
            border-radius: 10px;
            padding: 15px;
            margin: 20px 0;
            word-break: break-all;
            font-family: monospace;
            font-size: 12px;
        }
        .copy-btn {
            background: #667eea;
            color: white;
            border: none;
            padding: 12px 30px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
            transition: background 0.3s;
        }
        .copy-btn:hover {
            background: #5568d3;
        }
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 10px;
            border-radius: 5px;
            margin-top: 15px;
            display: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔗 مشاهده مشخصات اکانت</h1>
        <p>لینک اشتراک شما:</p>
        <div class="subscription-link" id="subscriptionLink">در حال بارگذاری...</div>
        <button class="copy-btn" onclick="copyLink()">📋 کپی لینک</button>
        <div class="success-message" id="successMessage">✅ لینک با موفقیت کپی شد!</div>
    </div>

    <script>
        // دریافت لینک از query parameter
        const urlParams = new URLSearchParams(window.location.search);
        const subscriptionLink = urlParams.get('link');
        
        if (subscriptionLink) {
            document.getElementById('subscriptionLink').textContent = decodeURIComponent(subscriptionLink);
            // باز کردن لینک در یک تب جدید (اختیاری)
            // window.open(decodeURIComponent(subscriptionLink), '_blank');
        } else {
            document.getElementById('subscriptionLink').textContent = 'لینک یافت نشد!';
        }
        
        function copyLink() {
            const link = document.getElementById('subscriptionLink').textContent;
            if (link && link !== 'در حال بارگذاری...' && link !== 'لینک یافت نشد!') {
                navigator.clipboard.writeText(link).then(function() {
                    const successMsg = document.getElementById('successMessage');
                    successMsg.style.display = 'block';
                    setTimeout(function() {
                        successMsg.style.display = 'none';
                    }, 2000);
                });
            }
        }
    </script>
</body>
</html>

