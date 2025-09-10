<?php
/**
 * Renders the floating CTA banner at the bottom of the page.
 * This file contains all the HTML, CSS, and JS for the banner.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

function lr_render_cta_banner($cta_text) {
    $ios_link = 'https://apps.apple.com/app/apple-store/id1576102938?pt=123205760&ct=explore_pages&mt=8';
    $android_link = 'https://play.google.com/store/apps/details?id=com.letsroll.android&referrer=utm_source%3Dexplore_pages%26utm_medium%3Dweb';
    ?>
    <style>
        .lr-cta-banner {
            position: fixed;
            bottom: -200px; /* Start hidden */
            left: 0;
            width: 100%;
            background-color: #fff;
            color: #333;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            padding: 15px;
            box-sizing: border-box;
            z-index: 1000;
            transition: bottom 0.5s ease-in-out;
            border-top: 1px solid #e0e0e0;
        }
        .lr-cta-banner-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            max-width: 1100px; /* Constrain the width on desktop */
            margin: 0 auto;   /* Center the content */
            position: relative;
        }
        .lr-cta-main-content { /* New wrapper for icon and text */
            display: flex;
            align-items: center;
            flex-grow: 1;
        }
        .lr-cta-banner.visible {
            bottom: 0;
        }
        .lr-cta-content {
            flex-grow: 1;
            margin: 0 15px;
        }
        .lr-cta-content p {
            margin: 0;
            font-size: 14px;
            line-height: 1.4;
        }
        .lr-cta-icon img {
            width: 50px;
            height: 50px;
            border-radius: 10px;
        }
        .lr-cta-buttons {
            display: flex;
            gap: 10px;
            align-items: center; /* Vertically align the button images */
        }
        .lr-cta-buttons a {
            /* MODIFIED: Make the link a block to give it a defined height */
            display: inline-block;
            height: 35px; /* Adjust height as needed */
        }
        /* Style the new image-based buttons */
        .lr-cta-buttons img {
            height: 100%; /* Make the image fill the anchor tag's height */
            width: auto;
        }
        .lr-cta-close {
            position: absolute;
            top: -10px;  /* Position relative to the inner container */
            right: -5px;
            background: none;
            border: none;
            font-size: 24px;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #999;
        }

        /* Mobile Styles */
        @media (max-width: 768px) {
            .lr-cta-banner-inner, .lr-cta-main-content {
                flex-direction: column;
                text-align: center;
            }
             .lr-cta-banner {
                padding-bottom: 20px;
            }
            .lr-cta-icon { margin-bottom: 10px; }
            .lr-cta-content { margin: 0 0 15px 0; }
            .lr-cta-buttons { justify-content: center; }
            .lr-cta-close { top: 0px; right: 5px; }
        }

        /* --- ADDED: Desktop Styles for a Taller Banner --- */
        @media (min-width: 769px) {
            .lr-cta-banner-inner {
                flex-direction: column; /* Stack content and buttons vertically */
                gap: 15px;
                padding-top: 20px;
                padding-bottom: 20px;
            }
            .lr-cta-main-content {
                justify-content: center; /* Center icon and text as a group */
            }
            .lr-cta-content {
                text-align: left;
                max-width: 550px; /* Keep text from getting too wide */
            }
            .lr-cta-close {
                top: 10px;
                right: 15px;
            }
        }
    </style>

    <div id="lr-cta-banner" class="lr-cta-banner">
        <div class="lr-cta-banner-inner">
            <button id="lr-cta-close" class="lr-cta-close">&times;</button>
            
            <div class="lr-cta-main-content">
                <div class="lr-cta-icon">
                    <img src="https://lets-roll.app/wp-content/uploads/main-logo.svg" alt="Let's Roll App Icon">
                </div>
                
                <div class="lr-cta-content">
                    <p><?php echo esc_html($cta_text); ?></p>
                </div>
            </div>

            <div class="lr-cta-buttons">
                <a href="<?php echo esc_url($ios_link); ?>" class="lr-cta-ios" target="_blank" rel="noopener noreferrer">
                    <img src="https://lets-roll.app/wp-content/uploads/Download_on_the_App_Store_Badge.svg" alt="Download on the App Store">
                </a>
                <a href="<?php echo esc_url($android_link); ?>" class="lr-cta-android" target="_blank" rel="noopener noreferrer">
                    <img src="https://lets-roll.app/wp-content/uploads/Google_Play_Store_badge_EN.svg" alt="Get it on Google Play">
                </a>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const banner = document.getElementById('lr-cta-banner');
            const closeButton = document.getElementById('lr-cta-close');

            // Don't show the banner if it has been closed before.
            if (sessionStorage.getItem('lrCtaBannerClosed') !== 'true') {
                // Animate the banner in after a short delay
                setTimeout(() => {
                    banner.classList.add('visible');
                }, 1500);
            }

            closeButton.addEventListener('click', function() {
                banner.classList.remove('visible');
                // Use sessionStorage to remember that the banner was closed for this session.
                sessionStorage.setItem('lrCtaBannerClosed', 'true');
            });
        });
    </script>
    <?php
}






