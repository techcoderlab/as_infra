<?php

add_action('wp_head', 'add_agency_tracker_script');

function add_agency_tracker_script()
{
    ?>
    <script id="agency-tracker-final">
        (function() {
            "use strict";

            // --- CONFIGURATION ---
            var CONFIG = {
                id: "ChuGtaDI", // Your SaaS Form ID
                selector: "#wpcf7-f18-p2-o1 form", // CSS Selector for the form
                endpoint: "https://renunciatory-unacerbic-ricky.ngrok-free.dev/api/public/external/form/submit"
            };
            // ---------------------

            /**
             * 1. INJECTOR: Adds the hidden ID field to the form
             * Runs on load and ensures we don't duplicate inputs.
             */
            function injectIdentity() {
                var forms = document.querySelectorAll(CONFIG.selector);

                for (var i = 0; i < forms.length; i++) {
                    var form = forms[i];

                    // Skip if already injected
                    if (form.getAttribute("data-agency-tracked")) continue;

                    var input = document.createElement("input");
                    input.type = "hidden";
                    input.name = "agency_form_identity";
                    input.value = CONFIG.id;

                    form.appendChild(input);
                    form.setAttribute("data-agency-tracked", "true");
                }
            }

            // Run injection immediately if DOM is ready, otherwise wait for it
            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", injectIdentity);
            } else {
                injectIdentity();
            }

            /**
             * 2. INTERCEPTOR: Listens for submissions globally
             * Uses event delegation to catch forms even if they load via AJAX.
             */
            document.addEventListener("submit", function(e) {
                var form = e.target;

                // SECURITY: Ignore password forms
                if (form.querySelector('input[type="password"]')) return;

                // IDENTITY CHECK: Verify this is the tracked form
                var identityField = form.querySelector('input[name="agency_form_identity"]');
                if (!identityField || identityField.value !== CONFIG.id) return;

                // 3. CAPTURE DATA
                var formData = new FormData(form);
                var data = {};

                // Convert FormData to JSON (Handling multi-selects/checkboxes correctly)
                formData.forEach(function(value, key) {
                    if (key === 'agency_form_identity') return; // Exclude internal ID

                    // This specific logic in your JS snippet:
                    if (Object.prototype.hasOwnProperty.call(data, key)) {
                        if (!Array.isArray(data[key])) {
                            data[key] = [data[key]]; // Convert single value to array
                        }
                        data[key].push(value); // Add the new value to the existing array
                    } else {
                        data[key] = value; // First time seeing this key? Just set it.
                    }

                });

                // 4. PAYLOAD CONSTRUCTION
                // Note: IP is intentionally removed. Laravel will detect it from the request headers.
                var payload = JSON.stringify({
                    data: {
                        formId: CONFIG.id,
                        formSource: 'wordpressform',
                        formName: document.title,
                        fields: data,
                        meta: {
                            url: window.location.href,
                            referrer: document.referrer || 'direct'
                        }
                    }
                });

                // 5. SEND (Beacon / Keepalive)
                // 'keepalive: true' ensures the request completes even if the page redirects immediately.
                if (window.fetch) {
                    window.fetch(CONFIG.endpoint, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/json",
                            "ngrok-skip-browser-warning": "true" // Needed for your Dev Env
                        },
                        body: payload,
                        keepalive: true
                    }).catch(function(err) {
                        console.error("Tracker Error:", err);
                    });
                }

            }, true); // Use Capture Phase to catch events before other scripts stop them
        })();
    </script>
<?php
}
