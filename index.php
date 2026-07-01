<?php
    require 'DMS.php';

    $dms = new DMS();

    
?>

<!DOCTYPE html>
    <html lang="en">
        <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
            <title> Document Management System</title>
            <link href="style.css" rel="stylesheet">
            <link rel="stylesheet" href="bootstrap.css">
        </head>
<body class="skin-admin">
    <div class="landing-page">
    
    <header class="landing-header">
        <div class="landing-brand">

        <a href="#" onclick="openLogoPreview()" style="text-decoration: none;">
            <span class="kiwi-icon">&#x1F95D;</span>
        </a>
        <span>Kiwi DMS</span>
        </div>
        <nav class="landing-nav">
            <a href="#about">About</a>
            <a href="#why-us">Why Us</a>
            <a href="login.php" class="btn btn-outline">Login</a>
        </nav>
    </header>

    <main class="landing-main">
        <section class="landing-hero">
        <div>
            <p class="landing-eyebrow">Secure • Simple • Organized</p>
            <h1>Manage your documents with confidence.</h1>
            <p class="landing-text">Kiwi DMS helps your team store, share, and track documents in one clean workspace.</p>
            <div class="landing-actions">
                <a href="login.php" class="btn btn-primary">Go to Login</a>
                <a href="#about" class="btn btn-outline">Learn More</a>
            </div>
        </div>
        <div class="landing-card">
            <h3>What you can do</h3>
            <ul>
                <li>Upload and organize documents</li>
                <li>Share files securely with others</li>
                <li>Track activity and version history</li>
            </ul>
        </div>
        </section>

        <section id="about" class="landing-section">
            <h2>About Kiwi DMS</h2>
            <p>Kiwi DMS is a lightweight document management system built for teams that want a practical way to manage files without unnecessary complexity.</p>
        </section>

        <section id="why-us" class="landing-section">
        <h2>Why Kiwi DMS?</h2>
        <div class="grid-container">
            <div class="card">
                <h3>Simple Workflow</h3>
                <p>Keep document handling clear with a user-friendly interface and focused actions.</p>
            </div>
            <div class="card">
                <h3>Role-Based Access</h3>
                <p>Admins, contributors, and casual users each get the access they need.</p>
            </div>
            <div class="card">
                <h3>Built-In Security</h3>
                <p>Secure login, protected uploads, and audit tracking help keep your content organized.</p>
            </div>
        </div>
        </section>

        <section class="landing-section landing-cta">
            <h2>Ready to get started?</h2>
            <a href="login.php" class="btn btn-primary">Login to your workspace</a>
        </section>
    </main>

    <footer class="landing-footer">
        <div class="footer-top">
            <div class="footer-brand-side">
                <div class="landing-brand">
                    <span class="kiwi-icon">&#x1F95D;</span>
                    <span>Kiwi DMS</span>
                </div>
                <p class="footer-tagline">Organizing files cleanly, securing work simply.</p>
            </div>
        <div class="footer-links-side">
            <div class="footer-col">
                <h4>Navigation</h4>
                <a href="#">Back to Top</a>
                <a href="#about">About System</a>
                <a href="#why-us">Why Us</a>
            </div>
            <div class="footer-col">
                <h4>Access</h4>
                <a href="login.php">Workspace Login</a>
                <span class="footer-note">Registration open for Admin & Contributor only</span>
            </div>
        </div>
        </div>
        <div class="footer-bottom">
        <p>&copy; 2026 Kiwi DMS. All rights reserved.</p>
        <div class="footer-status">
            <span class="status-dot"></span> Allen Gabriel S. Gaspar
        </div>
        </div>
    </footer>

    </div>

    <div id="logoPreviewModal" class="logo-modal-overlay" onclick="closeLogoPreview()">
        <div class="logo-modal-content" onclick="event.stopPropagation()">
            <span class="logo-modal-close" onclick="closeLogoPreview()">&times;</span>
            <div class="preview-asset-box">
                <span style="font-size: 6rem; line-height: 1;">&#x1F95D;</span>
            </div>
        </div>
    </div>

    <script src="../jquery.js"></script>

    <script>
        function openLogoPreview() {
            document.getElementById('logoPreviewModal').classList.add('is-active');
            document.body.style.overflow = 'hidden';
        }

        function closeLogoPreview() {
            document.getElementById('logoPreviewModal').classList.remove('is-active');
            document.body.style.overflow = '';
        }
    </script>

</body>
</html>