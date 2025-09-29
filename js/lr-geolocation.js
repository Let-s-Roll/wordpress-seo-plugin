document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('lr-nearby-content-container');

    // If the container is not on the page, or if it already has content from the server, do nothing.
    if (!container || container.querySelector('.lr-grid')) {
        return;
    }

    navigator.geolocation.getCurrentPosition(function(position) {
        // Success: Send coordinates to WordPress AJAX endpoint
        const lat = position.coords.latitude;
        const lon = position.coords.longitude;

        const formData = new FormData();
        formData.append('action', 'lr_get_nearby_content');
        formData.append('lat', lat);
        formData.append('lon', lon);

        // Use the global ajaxurl variable provided by WordPress
        fetch(ajaxurl, {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(html => {
            container.innerHTML = html;
        })
        .catch(error => {
            container.innerHTML = '<p>Error loading nearby content.</p>';
        });

    }, function(error) {
        // Failure or permission denied: Display the failure message
        let message = 'Could not automatically detect your location. Please explore our featured cities below.';
        if (error.code === error.PERMISSION_DENIED) {
            message = 'You denied the request for Geolocation. Please explore our featured cities below.';
        }
        container.innerHTML = '<p>' + message + '</p>';
    });
});
