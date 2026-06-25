const ApiClient = {
    async postJson(url, payload) {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });

        return response.json();
    },

    async getJson(url) {
        const response = await fetch(url);

        return response.json();
    },

    sendChatStream(message, sessionId) {
        return fetch('/api/v1/chat/stream', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                message,
                session_id: sessionId
            })
        });
    },

    sendChat(message) {
        return this.postJson('/api/v1/chat', { message });
    },

    listSessions() {
        return this.getJson('/api/v1/sessions');
    },

    getSessionMessages(sessionId) {
        return this.getJson('/api/v1/sessions/' + encodeURIComponent(sessionId) + '/messages');
    },

    createKnowledgeChunk(payload) {
        return this.postJson('/api/v1/knowledge-chunks', payload);
    }
};
