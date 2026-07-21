/**
 * VY-AI CRM Unified CKEditor 4 Helper
 * Centralizes editor loading, image resizing, and upload configuration.
 */

window.vyEditorInstances = {};

async function vyInitEditor(selector, options = {}) {
    const el = document.querySelector(selector);
    if (!el) return null;

    // Load CKEditor 4 Standard Build if not already loaded
    if (typeof CKEDITOR === 'undefined') {
        await new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = 'https://cdn.ckeditor.com/4.22.1/standard/ckeditor.js';
            script.onload = resolve;
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    try {
        const id = el.id || el.name;
        // Clean up any existing CKEditor instance
        if (CKEDITOR.instances[id]) {
            CKEDITOR.instances[id].destroy(true);
        }

        const editor = CKEDITOR.replace(id, {
            height: options.height || 260,
            filebrowserImageUploadUrl: '/api/upload_image.php',
            // Allow all HTML tags, classes, and styles (so images can have styles)
            allowedContent: true,
            // Handle file upload response mapping from CKEditor 5 JSON format to CKEditor 4 format
            on: {
                fileUploadResponse: function(evt) {
                    evt.stop();
                    const data = evt.data;
                    const xhr = data.fileLoader.xhr;
                    try {
                        const response = JSON.parse(xhr.responseText);
                        if (response.uploaded) {
                            data.url = response.url;
                        } else {
                            data.message = response.error ? response.error.message : 'Upload failed';
                            evt.cancel();
                        }
                    } catch (err) {
                        data.message = 'Invalid server response';
                        evt.cancel();
                    }
                }
            }
        });

        // Interface compatibility adapter with CKEditor 5 standard methods
        const adapter = {
            getData: () => editor.getData(),
            setData: (val) => editor.setData(val),
            insertText: (text) => {
                editor.insertText(text);
            },
            insertHtml: (html) => {
                editor.insertHtml(html);
            }
        };

        window.vyEditorInstances[selector] = adapter;
        return adapter;
    } catch (e) {
        console.error('Failed to initialize VY Editor:', e);
        return null;
    }
}
