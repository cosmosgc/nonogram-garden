import './gamepad-navigation';

const soundsButton = document.querySelector<HTMLButtonElement>('[data-audio-sounds]');
const musicButton = document.querySelector<HTMLButtonElement>('[data-audio-music]');
const siteMenu = document.querySelector<HTMLDetailsElement>('.site-menu');

if (siteMenu) {
    const mobileNavigation = window.matchMedia('(max-width: 760px)');
    siteMenu.open = !mobileNavigation.matches;
    mobileNavigation.addEventListener('change', event => {
        siteMenu.open = !event.matches;
    });
}

let context: AudioContext | null = null;
let soundsEnabled = false;
let musicEnabled = false;
let musicTimer: ReturnType<typeof setInterval> | undefined;
let noteIndex = 0;

function getContext(): AudioContext {
    context ??= new AudioContext();
    return context;
}

function note(frequency: number, duration: number, volume: number): void {
    const audio = getContext();
    const oscillator = audio.createOscillator();
    const gain = audio.createGain();
    const start = audio.currentTime;
    oscillator.type = 'sine';
    oscillator.frequency.value = frequency;
    gain.gain.setValueAtTime(0.0001, start);
    gain.gain.exponentialRampToValueAtTime(volume, start + 0.025);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
    oscillator.connect(gain);
    gain.connect(audio.destination);
    oscillator.start(start);
    oscillator.stop(start + duration);
}

function updateButton(button: HTMLButtonElement, label: string, enabled: boolean): void {
    button.setAttribute('aria-pressed', String(enabled));
    button.textContent = `${label}: ${enabled ? 'on' : 'off'}`;
}

soundsButton?.addEventListener('click', async () => {
    if (soundsEnabled) {
        soundsEnabled = false;
    } else {
        try {
            await getContext().resume();
            soundsEnabled = true;
            note(523.25, 0.12, 0.035);
        } catch {
            soundsEnabled = false;
        }
    }
    updateButton(soundsButton, 'Sound effects', soundsEnabled);
});

musicButton?.addEventListener('click', async () => {
    if (musicEnabled) {
        musicEnabled = false;
        clearInterval(musicTimer);
    } else {
        try {
            await getContext().resume();
            musicEnabled = true;
            const melody = [261.63, 329.63, 392, 329.63, 293.66, 349.23, 440, 349.23];
            const playNote = () => {
                if (!document.hidden) note(melody[noteIndex++ % melody.length]!, 1.1, 0.018);
            };
            playNote();
            musicTimer = setInterval(playNote, 1200);
        } catch {
            musicEnabled = false;
        }
    }
    updateButton(musicButton, 'Background music', musicEnabled);
});

document.addEventListener('click', event => {
    if (!soundsEnabled || !(event.target instanceof Element)) return;
    const button = event.target.closest('button');
    if (!button || button.disabled || button === soundsButton || button === musicButton) return;
    note(button.hasAttribute('data-player-cell') ? 392 : 440, 0.09, 0.025);
});
