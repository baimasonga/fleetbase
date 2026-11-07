// Reads a farmer's card QR code with the phone's back camera (jsQR, vendor/jsQR.js).

export async function scan(onCode) {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    throw new Error('This phone\'s browser can\'t use the camera here. Type the code from the card instead.');
  }
  if (typeof window.jsQR !== 'function') {
    throw new Error('The QR reader did not load. Type the code from the card instead.');
  }

  const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 } }, audio: false });

  const overlay = document.createElement('div');
  overlay.className = 'scanner';
  overlay.setAttribute('role', 'dialog');
  overlay.setAttribute('aria-label', 'Scan the farmer\'s card');
  const video = document.createElement('video');
  video.setAttribute('playsinline', '');
  video.muted = true;
  video.srcObject = stream;
  const frame = document.createElement('div');
  frame.className = 'scanner-frame';
  const controls = document.createElement('div');
  controls.className = 'scanner-bar';
  const hint = document.createElement('span');
  hint.textContent = 'Hold the QR on the card inside the frame.';
  const cancel = document.createElement('button');
  cancel.type = 'button';
  cancel.className = 'btn';
  cancel.textContent = 'Cancel';
  controls.append(hint, cancel);
  overlay.append(video, frame, controls);
  document.body.append(overlay);

  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  let done = false;
  let lastScan = 0;

  const stop = () => {
    done = true;
    stream.getTracks().forEach((t) => t.stop());
    overlay.remove();
  };
  cancel.addEventListener('click', stop);

  await video.play();

  const tick = (time) => {
    if (done) return;
    // About 6 reads a second is plenty and saves battery.
    if (time - lastScan > 160 && video.readyState >= 2) {
      lastScan = time;
      const scale = Math.min(1, 800 / Math.max(video.videoWidth, video.videoHeight));
      canvas.width = Math.round(video.videoWidth * scale);
      canvas.height = Math.round(video.videoHeight * scale);
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      const image = ctx.getImageData(0, 0, canvas.width, canvas.height);
      const result = window.jsQR(image.data, image.width, image.height, { inversionAttempts: 'dontInvert' });
      if (result && result.data) {
        if (navigator.vibrate) navigator.vibrate(80);
        stop();
        onCode(result.data);
        return;
      }
    }
    requestAnimationFrame(tick);
  };
  requestAnimationFrame(tick);

  return stop;
}
