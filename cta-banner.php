<?php
/**
 * Renders a simple, static, dismissible CTA banner using a CSS-only trick for AMP compatibility.
 */
function lr_render_cta_banner($cta_text) {
    // These links are for the app stores.
    $ios_link = 'https://apps.apple.com/app/apple-store/id1576102938?pt=123205760&ct=explore_pages&mt=8';
    $android_link = 'https://play.google.com/store/apps/details?id=com.letsroll.android&referrer=utm_source%3Dexplore_pages%26utm_medium%3Dweb';
    ?>
    <style>
        /* CSS-only dismissible banner */
        #lr-cta-checkbox {
            display: none;
        }
        #lr-cta-checkbox:checked + .lr-cta-banner {
            display: none;
        }
        .lr-cta-banner {
            position: fixed;
            bottom: 0;
            left: 0;
            width: 100%;
            background-color: #fff;
            color: #333;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding: 15px;
            box-sizing: border-box;
            z-index: 1000;
            border-top: 1px solid #e0e0e0;
        }
        .lr-cta-banner-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1100px;
            margin: 0 auto;
            position: relative;
        }
        .lr-cta-main-content {
            display: flex;
            align-items: center;
            flex-grow: 1;
        }
        .lr-cta-icon { margin-right: 15px; }
        .lr-cta-icon img { width: 50px; height: 50px; border-radius: 10px; }
        .lr-cta-content p { margin: 0; font-size: 14px; }
        .lr-cta-buttons { display: flex; gap: 10px; align-items: center; }
        .lr-cta-buttons a { display: inline-block; height: 40px; }
        .lr-cta-buttons img { height: 100%; width: auto; }
        .lr-cta-close {
            position: absolute;
            top: -10px;
            right: -5px;
            font-size: 24px;
            cursor: pointer;
            padding: 5px;
            line-height: 1;
            color: #999;
            z-index: 1001;
        }

        /* Desktop Styles for a Taller Banner */
        @media (min-width: 769px) {
            .lr-cta-banner-inner { flex-direction: column; gap: 15px; padding: 20px 0; }
            .lr-cta-main-content { justify-content: center; }
            .lr-cta-content { text-align: left; max-width: 550px; }
            .lr-cta-close { top: 10px; right: 15px; }
        }

        /* Mobile Styles */
        @media (max-width: 768px) {
            .lr-cta-banner-inner, .lr-cta-main-content { flex-direction: column; text-align: center; }
            .lr-cta-banner { padding-bottom: 20px; }
            .lr-cta-icon { margin-right: 0; margin-bottom: 10px; }
            .lr-cta-content { margin: 0 0 15px 0; }
            .lr-cta-buttons { justify-content: center; width: 100%; }
            .lr-cta-buttons a { max-width: 45%; height: auto; }
            .lr-cta-buttons img { width: 100%; height: auto; }
            .lr-cta-close { top: 0px; right: 5px; }
        }
    </style>

    <input type="checkbox" id="lr-cta-checkbox">
    <div class="lr-cta-banner">
        <label for="lr-cta-checkbox" class="lr-cta-close">&times;</label>
        <div class="lr-cta-banner-inner">
            <div class="lr-cta-main-content">
                <div class="lr-cta-icon">
                    <img src="https://lets-roll.app/wp-content/uploads/main-logo.svg" alt="Let's Roll App Icon">
                </div>
                <div class="lr-cta-content">
                    <p><?php echo esc_html($cta_text); ?></p>
                </div>
            </div>
            <div class="lr-cta-buttons">
                <a href="<?php echo esc_url($ios_link); ?>" target="_blank" rel="noopener noreferrer">
                    <img src="https://lets-roll.app/wp-content/uploads/Download_on_the_App_Store_Badge.svg" alt="Download on the App Store">
                </a>
                <a href="<?php echo esc_url($android_link); ?>" target="_blank" rel="noopener noreferrer">
                    <img src="https://lets-roll.app/wp-content/uploads/Google_Play_Store_badge_EN.svg" alt="Get it on Google Play">
                </a>
            </div>
        </div>
    </div>
    <?php
}

