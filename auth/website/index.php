<?php http_response_code(403); ?>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>403 - Access Denied | DirectSponsor Authentication</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        html {
            height: 100%;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            min-height: 100dvh;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #333;
            padding: 20px;
            box-sizing: border-box;
            overflow-x: hidden;
        }
        
        .error-container {
            background: white;
            padding: 3rem;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            text-align: center;
            max-width: 550px;
            width: 100%;
            max-height: 95vh;
            overflow-y: auto;
            position: relative;
            margin: auto;
        }
        
        .intro-section {
            margin-bottom: 2rem;
            padding-bottom: 2rem;
            border-bottom: 2px solid #f1f2f6;
        }
        
        .intro-question {
            font-size: 1.4rem;
            font-weight: 600;
            color: #2f3542;
            margin-bottom: 1.5rem;
        }
        
        .warning-image {
            max-width: 200px;
            width: 100%;
            height: auto;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .stern-message {
            font-size: 1.2rem;
            font-weight: 600;
            color: #ff4757;
            margin-bottom: 1rem;
        }
        
        .slow-warning {
            font-size: 1.1rem;
            color: #2f3542;
            margin-bottom: 0.5rem;
        }
        
        .error-code {
            font-size: 6rem;
            font-weight: 900;
            color: #ff4757;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }
        
        .error-title {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: #2f3542;
        }
        
        .error-message {
            font-size: 1.1rem;
            color: #57606f;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .service-info {
            background: #f1f2f6;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 2rem;
            border-left: 4px solid #3742fa;
        }
        
        .service-info h3 {
            color: #2f3542;
            margin-bottom: 0.5rem;
        }
        
        .endpoints {
            text-align: left;
            font-size: 0.9rem;
            color: #666;
        }
        
        .endpoints li {
            margin: 0.25rem 0;
        }
        
        .back-button {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 25px;
            font-size: 1rem;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            text-decoration: none;
            display: inline-block;
            margin: 0 10px;
        }
        
        .back-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .footer {
            margin-top: 2rem;
            font-size: 0.8rem;
            color: #a4b0be;
        }
        
        /* Brave browser specific fixes */
        @media screen and (-webkit-min-device-pixel-ratio:0) {
            body {
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="intro-section">
            <div class="intro-question">What are you looking at?</div>
            <img src="https://nimno.net/wp-content/uploads/2024/03/429_rockstardev.jpg" alt="Warning" class="warning-image">
            <div class="stern-message">It's not your business.</div>
            <div class="slow-warning"><em><strong>Slowly</strong></em> navigate back to home.</div>
        </div>
        
        <div class="error-code">403</div>
        <h1 class="error-title">DENIED</h1>
        <p class="error-message">
            This authentication service is restricted to authorized applications only.
            <br>Direct access is not permitted.
        </p>
        
        <div class="service-info">
            <h3>Authentication Service</h3>
            <p>Available endpoints for authorized applications:</p>
            <ul class="endpoints">
                <li><strong>jwt-login.php</strong> - User authentication</li>
                <li><strong>jwt-refresh.php</strong> - Token refresh</li>
                <li><strong>jwt-signup.php</strong> - User registration</li>
            </ul>
        </div>
        
        <button class="back-button" onclick="goBack()">← Go Back</button>
        <a href="https://directsponsor.org" class="back-button">DirectSponsor Home</a>
        
        <div class="footer">
            © 2025 DirectSponsor - Authentication Service
        </div>
    </div>
    
    <script>
        function goBack() {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = 'https://directsponsor.org';
            }
        }
    </script>
</body>
</html>
