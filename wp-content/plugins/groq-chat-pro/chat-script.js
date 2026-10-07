jQuery(document).ready(function($) {
    const chatContainer = $('#groq-chat-messages');

    function appendMessage(text, sender) {
        const className = sender === 'user' ? 'user-msg' : 'bot-msg';
        chatContainer.append(`<div class="${className}">${text}</div>`);
        chatContainer.scrollTop(chatContainer[0].scrollHeight);
    }

    $('#groq-send-btn').on('click', function() {
        const input = $('#groq-input');
        const message = input.val().trim();

        if (message === '') return;

        appendMessage(message, 'user');
        input.val('');

        // Loading state
        appendMessage('Escribiendo...', 'bot-loading');

        $.ajax({
            url: groq_vars.ajax_url,
            type: 'POST',
            data: {
                action: 'groq_chat_send',
                message: message,
                nonce: groq_vars.nonce
            },
            success: function(response) {
                $('.bot-loading').remove();
                if (response.success) {
                    appendMessage(response.data.reply, 'bot');
                } else {
                    appendMessage('Error: ' + response.data.message, 'bot');
                }
            },
            error: function() {
                $('.bot-loading').remove();
                appendMessage('Error de conexión.', 'bot');
            }
        });
    });

    // Enviar con Enter
    $('#groq-input').on('keypress', function(e) {
        if (e.which == 13) $('#groq-send-btn').click();
    });
});
