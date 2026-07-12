<?php
require_once __DIR__ . '/../bootstrap.php';
require_login();

$user = current_user();
$title = "Probador de Sonidos";
require __DIR__ . '/_header.php';
?>

<div style="max-width: 600px; margin: 40px auto; padding: 20px;">
    <!-- CARD PRINCIPAL -->
    <div class="card" style="padding: 30px; border-radius: 28px; background: #ffffff; box-shadow: 0 15px 35px rgba(0, 0, 0, 0.05); border: 1px solid #f1f5f9;">
        
        <!-- ENCABEZADO -->
        <div style="text-align: center; margin-bottom: 30px;">
            <div style="width: 70px; height: 70px; border-radius: 50%; background: rgba(37, 99, 235, 0.08); display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 16px; color: #2563eb;">
                🔊
            </div>
            <h1 style="font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 6px; letter-spacing: -0.5px;">Panel de Prueba de Audio</h1>
            <p style="font-size: 14px; color: #64748b; font-weight: 500; margin: 0; line-height: 1.5;">
                Prueba la reproducción de tus archivos de sonido y verifica el estado de permisos de tu navegador.
            </p>
        </div>

        <!-- ESTADO DEL NAVEGADOR (AUDIO CONTEXT) -->
        <div id="status-card" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 20px; padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; transition: all 0.3s ease;">
            <div style="display: flex; align-items: center; gap: 12px;">
                <span id="status-emoji" style="font-size: 20px;">⚠️</span>
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 11px; font-weight: 800; color: #64748b; letter-spacing: 0.5px; text-transform: uppercase;">Estado de Audio</span>
                    <span id="status-text" style="font-size: 14px; font-weight: 700; color: #334155;">Esperando interacción...</span>
                </div>
            </div>
            <button onclick="unlockAudioDirectly()" id="unlock-btn" style="background: #2563eb; color: #ffffff; border: none; padding: 8px 16px; border-radius: 12px; font-size: 12px; font-weight: 800; cursor: pointer; transition: all 0.2s ease; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);">
                Activar Audio
            </button>
        </div>

        <!-- LISTA DE SONIDOS -->
        <h3 style="font-size: 14px; font-weight: 800; color: #0f172a; margin: 0 0 12px 4px; letter-spacing: 0.5px; text-transform: uppercase;">Sonidos de la App</h3>
        <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 30px;">
            
            <!-- DELIVERED.MP3 -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-radius: 18px; background: #fff; border: 1.5px solid #f1f5f9; transition: transform 0.2s ease, border-color 0.2s ease;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.borderColor='#e2e8f0';" onmouseleave="this.style.transform='none'; this.style.borderColor='#f1f5f9';">
                <div style="display: flex; flex-direction: column; gap: 2px;">
                    <span style="font-size: 14px; font-weight: 700; color: #1e293b;">Confirmación de Entrega (`delivered.mp3`)</span>
                    <span style="font-size: 11px; color: #64748b; font-weight: 500;">Ubicación: `assets/sounds/delivered.mp3`</span>
                </div>
                <button onclick="playLocalSound('<?= esc(delivery_app_url('assets/sounds/delivered.mp3')) ?>')" style="width: 40px; height: 40px; border-radius: 50%; background: #10b981; border: none; color: #fff; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.2); transition: transform 0.1s ease;" onmousedown="this.style.transform='scale(0.9)'" onmouseup="this.style.transform='none'">
                    ▶️
                </button>
            </div>

            <!-- NOTIFICATION.MP3 -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-radius: 18px; background: #fff; border: 1.5px solid #f1f5f9; transition: transform 0.2s ease, border-color 0.2s ease;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.borderColor='#e2e8f0';" onmouseleave="this.style.transform='none'; this.style.borderColor='#f1f5f9';">
                <div style="display: flex; flex-direction: column; gap: 2px;">
                    <span style="font-size: 14px; font-weight: 700; color: #1e293b;">Alerta Radar (`notification.mp3`)</span>
                    <span style="font-size: 11px; color: #64748b; font-weight: 500;">Ubicación: `assets/sounds/notification.mp3`</span>
                </div>
                <button onclick="playLocalSound('<?= esc(delivery_app_url('assets/sounds/notification.mp3')) ?>')" style="width: 40px; height: 40px; border-radius: 50%; background: #2563eb; border: none; color: #fff; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.2); transition: transform 0.1s ease;" onmousedown="this.style.transform='scale(0.9)'" onmouseup="this.style.transform='none'">
                    ▶️
                </button>
            </div>

            <!-- ORDER_TAKEN.MP3 -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-radius: 18px; background: #fff; border: 1.5px solid #f1f5f9; transition: transform 0.2s ease, border-color 0.2s ease;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.borderColor='#e2e8f0';" onmouseleave="this.style.transform='none'; this.style.borderColor='#f1f5f9';">
                <div style="display: flex; flex-direction: column; gap: 2px;">
                    <span style="font-size: 14px; font-weight: 700; color: #1e293b;">Pedido Tomado (`order_taken.mp3`)</span>
                    <span style="font-size: 11px; color: #64748b; font-weight: 500;">Ubicación: `assets/sounds/order_taken.mp3`</span>
                </div>
                <button onclick="playLocalSound('<?= esc(delivery_app_url('assets/sounds/order_taken.mp3')) ?>')" style="width: 40px; height: 40px; border-radius: 50%; background: #f59e0b; border: none; color: #fff; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(245, 158, 11, 0.2); transition: transform 0.1s ease;" onmousedown="this.style.transform='scale(0.9)'" onmouseup="this.style.transform='none'">
                    ▶️
                </button>
            </div>

        </div>

        <!-- SECCIÓN DE SONIDOS SUBIDOS (UPLOADS) -->
        <h3 style="font-size: 14px; font-weight: 800; color: #0f172a; margin: 0 0 12px 4px; letter-spacing: 0.5px; text-transform: uppercase;">Sonidos del Servidor (Uploads)</h3>
        <div style="display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px;">
            
            <!-- ARRIVED.MP3 -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-radius: 18px; background: #fff; border: 1.5px solid #f1f5f9; transition: transform 0.2s ease, border-color 0.2s ease;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.borderColor='#e2e8f0';" onmouseleave="this.style.transform='none'; this.style.borderColor='#f1f5f9';">
                <div style="display: flex; flex-direction: column; gap: 2px;">
                    <span style="font-size: 14px; font-weight: 700; color: #1e293b;">Delivery en local (`delivery_arrived.mp3`)</span>
                    <span style="font-size: 11px; color: #64748b; font-weight: 500;">Ubicación: `uploads/sounds/delivery_arrived.mp3`</span>
                </div>
                <button onclick="playLocalSound('<?= esc(delivery_app_url('uploads/sounds/delivery_arrived.mp3')) ?>')" style="width: 40px; height: 40px; border-radius: 50%; background: #4f46e5; border: none; color: #fff; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.2); transition: transform 0.1s ease;" onmousedown="this.style.transform='scale(0.9)'" onmouseup="this.style.transform='none'">
                    ▶️
                </button>
            </div>

            <!-- COMPLETED.MP3 -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-radius: 18px; background: #fff; border: 1.5px solid #f1f5f9; transition: transform 0.2s ease, border-color 0.2s ease;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.borderColor='#e2e8f0';" onmouseleave="this.style.transform='none'; this.style.borderColor='#f1f5f9';">
                <div style="display: flex; flex-direction: column; gap: 2px;">
                    <span style="font-size: 14px; font-weight: 700; color: #1e293b;">Pedido Completado (`delivery_completed.mp3`)</span>
                    <span style="font-size: 11px; color: #64748b; font-weight: 500;">Ubicación: `uploads/sounds/delivery_completed.mp3`</span>
                </div>
                <button onclick="playLocalSound('<?= esc(delivery_app_url('uploads/sounds/delivery_completed.mp3')) ?>')" style="width: 40px; height: 40px; border-radius: 50%; background: #06b6d4; border: none; color: #fff; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(6, 182, 212, 0.2); transition: transform 0.1s ease;" onmousedown="this.style.transform='scale(0.9)'" onmouseup="this.style.transform='none'">
                    ▶️
                </button>
            </div>

            <!-- SUCCESS.MP3 -->
            <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-radius: 18px; background: #fff; border: 1.5px solid #f1f5f9; transition: transform 0.2s ease, border-color 0.2s ease;" onmouseenter="this.style.transform='translateY(-2px)'; this.style.borderColor='#e2e8f0';" onmouseleave="this.style.transform='none'; this.style.borderColor='#f1f5f9';">
                <div style="display: flex; flex-direction: column; gap: 2px;">
                    <span style="font-size: 14px; font-weight: 700; color: #1e293b;">Éxito (`success.mp3`)</span>
                    <span style="font-size: 11px; color: #64748b; font-weight: 500;">Ubicación: `uploads/sounds/success.mp3`</span>
                </div>
                <button onclick="playLocalSound('<?= esc(delivery_app_url('uploads/sounds/success.mp3')) ?>')" style="width: 40px; height: 40px; border-radius: 50%; background: #ec4899; border: none; color: #fff; font-size: 16px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(236, 72, 153, 0.2); transition: transform 0.1s ease;" onmousedown="this.style.transform='scale(0.9)'" onmouseup="this.style.transform='none'">
                    ▶️
                </button>
            </div>

        </div>

    </div>
</div>

<script>
    // Verificar si el AudioContext global está desbloqueado
    function checkAudioState() {
        const statusCard = document.getElementById('status-card');
        const statusEmoji = document.getElementById('status-emoji');
        const statusText = document.getElementById('status-text');
        const unlockBtn = document.getElementById('unlock-btn');

        if (window.playNotificationSound) {
            // Verificar si el audio ya está desbloqueado internamente por gesto
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (AudioContextClass) {
                const tempCtx = new AudioContextClass();
                if (tempCtx.state === 'running') {
                    statusCard.style.background = '#f0fdf4';
                    statusCard.style.borderColor = '#bbf7d0';
                    statusEmoji.innerText = '🟢';
                    statusText.innerText = 'Audio Activado y Listo';
                    statusText.style.color = '#166534';
                    unlockBtn.style.display = 'none';
                } else {
                    statusCard.style.background = '#fef3c7';
                    statusCard.style.borderColor = '#fde68a';
                    statusEmoji.innerText = '⚠️';
                    statusText.innerText = 'Audio Suspendido (Requiere interacción)';
                    statusText.style.color = '#92400e';
                    unlockBtn.style.display = 'block';
                }
                tempCtx.close();
            }
        }
    }

    async function unlockAudioDirectly() {
        if (window.playNotificationSound) {
            // Intentar Web Audio Context
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (AudioContextClass) {
                const ctx = new AudioContextClass();
                await ctx.resume();
                ctx.close();
            }
            
            // Forzar el trigger silent
            const silentSrc = "data:audio/wav;base64,UklGRigAAABXQVZFZm10IBIAAAABAAEARKwAAIhYAQACABAAAABkYXRhAgAAAAAA";
            const audio = new Audio(silentSrc);
            audio.play().then(() => {
                const banner = document.getElementById('audio-unlock-banner');
                if (banner) banner.remove();
                
                // Actualizar estado visual
                const statusCard = document.getElementById('status-card');
                const statusEmoji = document.getElementById('status-emoji');
                const statusText = document.getElementById('status-text');
                const unlockBtn = document.getElementById('unlock-btn');
                
                statusCard.style.background = '#f0fdf4';
                statusCard.style.borderColor = '#bbf7d0';
                statusEmoji.innerText = '🟢';
                statusText.innerText = 'Audio Activado y Listo';
                statusText.style.color = '#166534';
                unlockBtn.style.display = 'none';
            }).catch(e => {
                console.log(e);
            });
        }
    }

    function playLocalSound(src) {
        if (window.playNotificationSound) {
            window.playNotificationSound(src);
        } else {
            const audio = new Audio(src);
            audio.play().catch(e => {
                alert("Error al reproducir: " + e.message + "\nPor favor activa los sonidos usando el botón superior.");
            });
        }
    }

    window.addEventListener('load', () => {
        setTimeout(checkAudioState, 1500);
    });
</script>

<?php 
require __DIR__ . '/_footer.php'; 
?>
