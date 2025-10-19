<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jahongir Hotels - Voice Booking Agent Test</title>
    <script src="https://unpkg.com/@livekit/components-core@latest/dist/components-core.umd.js"></script>
    <script src="https://unpkg.com/@livekit/components-react@latest/dist/components-react.umd.js"></script>
    <script src="https://unpkg.com/livekit-client@latest/dist/livekit-client.umd.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            min-height: 100vh;
        }
        .container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .header h1 {
            margin: 0;
            font-size: 2.5em;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }
        .header p {
            margin: 10px 0 0 0;
            opacity: 0.9;
        }
        .status {
            background: rgba(255, 255, 255, 0.2);
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        .status.connected {
            background: rgba(76, 175, 80, 0.3);
        }
        .status.error {
            background: rgba(244, 67, 54, 0.3);
        }
        .controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-bottom: 20px;
        }
        button {
            background: rgba(255, 255, 255, 0.2);
            border: 2px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 12px 24px;
            border-radius: 25px;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        button:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
        }
        button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .conversation {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 20px;
            max-height: 300px;
            overflow-y: auto;
            margin-bottom: 20px;
        }
        .message {
            margin-bottom: 15px;
            padding: 10px 15px;
            border-radius: 20px;
            max-width: 80%;
        }
        .user {
            background: rgba(255, 255, 255, 0.2);
            margin-left: auto;
            text-align: right;
        }
        .agent {
            background: rgba(76, 175, 80, 0.3);
            margin-right: auto;
        }
        .visualizer {
            height: 60px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: rgba(255, 255, 255, 0.7);
        }
        .instructions {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 10px;
            font-size: 14px;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🏨 Jahongir Hotels</h1>
            <p>Voice Booking Agent Test Interface</p>
        </div>

        <div id="status" class="status">
            Ready to connect...
        </div>

        <div class="visualizer" id="visualizer">
            Audio visualization will appear here
        </div>

        <div class="controls">
            <button id="connectBtn" onclick="connectToAgent()">Connect to Voice Agent</button>
            <button id="disconnectBtn" onclick="disconnect()" disabled>Disconnect</button>
        </div>

        <div class="conversation" id="conversation">
            <div class="message agent">
                <strong>Agent:</strong> Welcome! Click "Connect to Voice Agent" to start.
            </div>
        </div>

        <div class="instructions">
            <h3>🎤 How to Use:</h3>
            <ul>
                <li>Click "Connect to Voice Agent" to start the conversation</li>
                <li>Allow microphone access when prompted</li>
                <li>Speak naturally to the hotel booking agent</li>
                <li>Try asking: "I'd like to book a room for 2 guests next weekend"</li>
                <li>Or: "Check availability for January 15th to 17th"</li>
            </ul>
        </div>
    </div>

    <script>
        let room = null;
        let audioContext = null;
        let analyser = null;
        let dataArray = null;
        let animationId = null;

        function updateStatus(message, type = '') {
            const statusEl = document.getElementById('status');
            statusEl.textContent = message;
            statusEl.className = 'status' + (type ? ' ' + type : '');
        }

        function addMessage(speaker, text) {
            const conversation = document.getElementById('conversation');
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${speaker}`;
            messageDiv.innerHTML = `<strong>${speaker === 'user' ? 'You' : 'Agent'}:</strong> ${text}`;
            conversation.appendChild(messageDiv);
            conversation.scrollTop = conversation.scrollHeight;
        }

        function updateVisualizer(volume) {
            const visualizer = document.getElementById('visualizer');
            const bars = 20;
            let html = '';

            for (let i = 0; i < bars; i++) {
                const height = Math.random() * 40 + 10;
                html += `<div style="height:${height}px; width:3px; background:rgba(255,255,255,0.8); margin:0 1px; border-radius:2px;"></div>`;
            }

            visualizer.innerHTML = html;
        }

        async function connectToAgent() {
            try {
                updateStatus('Connecting to voice agent...');
                document.getElementById('connectBtn').disabled = true;

                // Initialize LiveKit client
                const { Room, RoomEvent, Track } = LiveKitClient;

                // Create room instance
                room = new Room({
                    adaptiveStream: true,
                    dynacast: true,
                });

                // Set up event listeners
                room
                    .on(RoomEvent.Connected, () => {
                        updateStatus('Connected to voice agent!', 'connected');
                        document.getElementById('disconnectBtn').disabled = false;
                        addMessage('agent', 'Hello! Welcome to Jahongir Hotels. I\'m your voice assistant. How can I help you today?');
                    })
                    .on(RoomEvent.Disconnected, () => {
                        updateStatus('Disconnected from voice agent');
                        document.getElementById('connectBtn').disabled = false;
                        document.getElementById('disconnectBtn').disabled = true;
                        addMessage('agent', 'Connection ended. Click connect to start again.');
                    })
                    .on(RoomEvent.ParticipantConnected, (participant) => {
                        console.log('Participant connected:', participant.identity);
                    })
                    .on(RoomEvent.TrackSubscribed, (track, publication, participant) => {
                        console.log('Track subscribed:', track.kind, 'from', participant.identity);
                        if (track.kind === Track.Kind.Audio) {
                            const audioElement = track.attach();
                            document.body.appendChild(audioElement);
                            setupAudioVisualizer(audioElement);
                        }
                    });

                // For demo purposes, we'll simulate connection
                // In production, you would use actual LiveKit server URL and token
                setTimeout(() => {
                    updateStatus('Connected to voice agent!', 'connected');
                    document.getElementById('disconnectBtn').disabled = false;
                    addMessage('agent', 'Hello! Welcome to Jahongir Hotels. I\'m your voice assistant. How can I help you today?');

                    // Simulate audio visualization
                    startVisualizer();
                }, 2000);

            } catch (error) {
                console.error('Connection error:', error);
                updateStatus('Connection failed: ' + error.message, 'error');
                document.getElementById('connectBtn').disabled = false;
            }
        }

        function disconnect() {
            if (room) {
                room.disconnect();
            }
            updateStatus('Disconnected');
            document.getElementById('connectBtn').disabled = false;
            document.getElementById('disconnectBtn').disabled = true;

            if (animationId) {
                cancelAnimationFrame(animationId);
                animationId = null;
            }

            const visualizer = document.getElementById('visualizer');
            visualizer.innerHTML = 'Audio visualization will appear here';
        }

        function setupAudioVisualizer(audioElement) {
            audioContext = new (window.AudioContext || window.webkitAudioContext)();
            analyser = audioContext.createAnalyser();
            const source = audioContext.createMediaElementSource(audioElement);
            source.connect(analyser);
            analyser.connect(audioContext.destination);

            analyser.fftSize = 256;
            const bufferLength = analyser.frequencyBinCount;
            dataArray = new Uint8Array(bufferLength);

            startVisualizer();
        }

        function startVisualizer() {
            function animate() {
                animationId = requestAnimationFrame(animate);

                if (analyser && dataArray) {
                    analyser.getByteFrequencyData(dataArray);
                    const average = dataArray.reduce((a, b) => a + b) / dataArray.length;
                    updateVisualizer(average);
                } else {
                    // Simulate audio activity for demo
                    updateVisualizer(Math.random() * 100);
                }
            }
            animate();
        }

        // Handle page unload
        window.addEventListener('beforeunload', () => {
            if (room) {
                room.disconnect();
            }
        });
    </script>
</body>
</html>