// @vitest-environment jsdom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import NonogramImageImporter from './NonogramImageImporter.vue';

let originalCreateObjectURL: typeof URL.createObjectURL | undefined;
let originalRevokeObjectURL: typeof URL.revokeObjectURL | undefined;

afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    if (originalCreateObjectURL) Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: originalCreateObjectURL });
    else Reflect.deleteProperty(URL, 'createObjectURL');
    if (originalRevokeObjectURL) Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: originalRevokeObjectURL });
    else Reflect.deleteProperty(URL, 'revokeObjectURL');
    originalCreateObjectURL = undefined;
    originalRevokeObjectURL = undefined;
    document.body.innerHTML = '';
});

describe('NonogramImageImporter', () => {
    it('runs cancellable worker analysis and ignores a late result after cancellation', async () => {
        document.body.innerHTML = '<div id="nonogram-image-importer" data-user-id="7" data-editor-new-url="/editor/new"></div>';
        class FakeWorker {
            static instances: FakeWorker[] = [];
            onmessage: ((event: MessageEvent) => void) | null = null;
            onerror: ((event: ErrorEvent) => void) | null = null;
            terminated = false;
            posted: { id: number; grid?: unknown; file?: File } | null = null;

            constructor(readonly url: URL) { FakeWorker.instances.push(this); }
            postMessage(message: { id: number; grid?: unknown; file?: File }) {
                this.posted = message;
                if (this.url.href.includes('image-decode.worker')) {
                    this.onmessage?.({ data: { id: message.id, raster: { width: 2, height: 1, data: new Uint8ClampedArray([0, 0, 0, 255, 255, 255, 255, 255]) } } } as MessageEvent);
                } else if (this.url.href.includes('image-conversion.worker')) {
                    this.onmessage?.({ data: { id: message.id, grid: { version: 1, width: 2, height: 1, cells: '10' } } } as MessageEvent);
                }
            }
            terminate() { this.terminated = true; }
        }
        vi.stubGlobal('Worker', FakeWorker as unknown as typeof Worker);
        vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 2, height: 1, close: vi.fn() }));
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
            drawImage: vi.fn(), getImageData: vi.fn(() => ({ data: new Uint8ClampedArray([0, 0, 0, 255, 255, 255, 255, 255]) })),
        } as unknown as CanvasRenderingContext2D);
        originalCreateObjectURL = URL.createObjectURL;
        originalRevokeObjectURL = URL.revokeObjectURL;
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:preview') });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });

        const wrapper = mount(NonogramImageImporter, { attachTo: document.getElementById('nonogram-image-importer')! });
        const input = wrapper.get('input[type="file"]');
        Object.defineProperty(input.element, 'files', { configurable: true, value: [new File([new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10])], 'source.png', { type: 'image/png' })] });
        await input.trigger('change');
        await flushPromises();
        await wrapper.findAll('button').find(button => button.text() === 'Update preview now')!.trigger('click');
        await flushPromises();
        await wrapper.findAll('button').find(button => button.text() === 'Analyze uniqueness')!.trigger('click');

        const analyzer = FakeWorker.instances[2];
        expect(wrapper.text()).toContain('Analyzing uniqueness');
        await wrapper.findAll('button').find(button => button.text() === 'Cancel analysis')!.trigger('click');
        expect(analyzer.terminated).toBe(true);
        expect(wrapper.text()).toContain('Analysis cancelled; uniqueness remains unknown.');
        analyzer.onmessage?.({ data: { id: analyzer.posted?.id, status: 'unique' } } as MessageEvent);
        expect(wrapper.text()).toContain('Client uniqueness result: unknown.');
        expect(wrapper.text()).not.toContain('Client uniqueness result: unique.');
        wrapper.unmount();
    });

    it('keeps the source local and generates an editable canonical grid preview', async () => {
        document.body.innerHTML = '<div id="nonogram-image-importer" data-user-id="7" data-editor-new-url="/Nonogram%20laravel/public/editor/new"></div>';
        const bitmap = { width: 2, height: 1, close: vi.fn() } as unknown as ImageBitmap;
        vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue(bitmap));
        vi.stubGlobal('Worker', undefined);
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        const canvasContext = {
            drawImage: vi.fn(),
            getImageData: vi.fn(() => ({
                data: new Uint8ClampedArray([0, 0, 0, 255, 255, 255, 255, 255]),
            })),
        };
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue(canvasContext as unknown as CanvasRenderingContext2D);
        originalCreateObjectURL = URL.createObjectURL;
        originalRevokeObjectURL = URL.revokeObjectURL;
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:local-preview') });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });

        const wrapper = mount(NonogramImageImporter, { attachTo: document.getElementById('nonogram-image-importer')! });
        expect(wrapper.get('a').attributes('href')).toBe('/Nonogram%20laravel/public/editor/new');
        const file = new File([new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10])], 'source.png', { type: 'image/png' });
        const input = wrapper.get('input[type="file"]').element as HTMLInputElement;
        Object.defineProperty(input, 'files', { configurable: true, value: [file] });
        await wrapper.get('input[type="file"]').trigger('change');
        await flushPromises();

        expect(wrapper.text()).toContain('source.png · 2×1');
        expect(wrapper.text()).toContain('not uploaded or saved');
        expect(canvasContext.drawImage).toHaveBeenCalledOnce();
        expect(bitmap.close).toHaveBeenCalledOnce();
        expect(fetchMock).not.toHaveBeenCalled();

        const dimensions = wrapper.findAll('input[type="number"]');
        await dimensions[0].setValue(2);
        await dimensions[1].setValue(1);
        await wrapper.get('button').trigger('click');
        expect(wrapper.text()).toContain('2×1 · 1 filled cell');
        expect(wrapper.text()).toContain('Row clues');
        expect(wrapper.get('[role="img"]').attributes('aria-label')).toBe('Generated 2 by 1 monochrome grid');
        await wrapper.findAll('button').find(button => button.text() === 'Analyze uniqueness')!.trigger('click');
        expect(wrapper.text()).toContain('Client uniqueness result: unique.');
        expect(wrapper.text()).toContain('server performs the authoritative publication check');
        expect(sessionStorage.getItem('nonogram:image-import:7')).toBeNull();
        wrapper.unmount();
    });

    it('cancels image decoding and ignores a worker result that arrives afterward', async () => {
        document.body.innerHTML = '<div id="nonogram-image-importer" data-user-id="7" data-editor-new-url="/editor/new"></div>';
        class PendingWorker {
            static instance: PendingWorker;
            onmessage: ((event: MessageEvent) => void) | null = null;
            onerror: ((event: ErrorEvent) => void) | null = null;
            terminated = false;
            constructor() { PendingWorker.instance = this; }
            postMessage() {}
            terminate() { this.terminated = true; }
        }
        vi.stubGlobal('Worker', PendingWorker as unknown as typeof Worker);
        originalCreateObjectURL = URL.createObjectURL;
        originalRevokeObjectURL = URL.revokeObjectURL;
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:pending') });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });

        const wrapper = mount(NonogramImageImporter, { attachTo: document.getElementById('nonogram-image-importer')! });
        const input = wrapper.get('input[type="file"]');
        Object.defineProperty(input.element, 'files', {
            configurable: true,
            value: [new File([new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10])], 'pending.png', { type: 'image/png' })],
        });
        await input.trigger('change');
        expect(wrapper.text()).toContain('Decoding image locally…');
        await wrapper.get('button').trigger('click');
        expect(PendingWorker.instance.terminated).toBe(true);
        PendingWorker.instance.onmessage?.({ data: { raster: { width: 1, height: 1, data: new Uint8ClampedArray([0, 0, 0, 255]) } } } as MessageEvent);
        await flushPromises();
        expect(wrapper.text()).not.toContain('Decoding image locally…');
        expect(wrapper.text()).not.toContain('1×1');
        wrapper.unmount();
    });

    it('converts automatically on image selection and updates when dimensions change', async () => {
        vi.useFakeTimers();
        document.body.innerHTML = '<div id="nonogram-image-importer" data-user-id="7" data-editor-new-url="/editor/new"></div>';
        vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 2, height: 1, close: vi.fn() }));
        vi.stubGlobal('Worker', undefined);
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
            drawImage: vi.fn(),
            getImageData: vi.fn(() => ({ data: new Uint8ClampedArray([0, 0, 0, 255, 255, 255, 255, 255]) })),
        } as unknown as CanvasRenderingContext2D);
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:live-preview') });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });

        const wrapper = mount(NonogramImageImporter, { attachTo: document.getElementById('nonogram-image-importer')! });
        const fileInput = wrapper.get('input[type="file"]');
        Object.defineProperty(fileInput.element, 'files', {
            configurable: true,
            value: [new File([new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10])], 'live.png', { type: 'image/png' })],
        });
        await fileInput.trigger('change');
        await flushPromises();
        await vi.advanceTimersByTimeAsync(180);
        await flushPromises();
        expect(wrapper.text()).toContain('10×10 ·');

        const dimensions = wrapper.findAll('input[type="number"]');
        await dimensions[0].setValue(2);
        await dimensions[1].setValue(1);
        await vi.advanceTimersByTimeAsync(180);
        await flushPromises();
        expect(wrapper.text()).toContain('2×1 ·');
        wrapper.unmount();
    });

    it('falls back to local conversion when the image worker fails', async () => {
        document.body.innerHTML = '<div id="nonogram-image-importer" data-user-id="7" data-editor-new-url="/editor/new"></div>';
        vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 2, height: 1, close: vi.fn() }));
        class FailedWorker {
            onerror: ((event: ErrorEvent) => void) | null = null;
            postMessage() { this.onerror?.({ message: 'worker could not load' } as ErrorEvent); }
            terminate() {}
        }
        vi.stubGlobal('Worker', FailedWorker as unknown as typeof Worker);
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
            drawImage: vi.fn(),
            getImageData: vi.fn(() => ({ data: new Uint8ClampedArray([0, 0, 0, 255, 255, 255, 255, 255]) })),
        } as unknown as CanvasRenderingContext2D);
        originalCreateObjectURL = URL.createObjectURL;
        originalRevokeObjectURL = URL.revokeObjectURL;
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:fallback') });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });

        const wrapper = mount(NonogramImageImporter, { attachTo: document.getElementById('nonogram-image-importer')! });
        const input = wrapper.get('input[type="file"]');
        Object.defineProperty(input.element, 'files', {
            configurable: true,
            value: [new File([new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10])], 'small.png', { type: 'image/png' })],
        });
        await input.trigger('change');
        await flushPromises();
        await wrapper.get('button').trigger('click');

        expect(wrapper.text()).toContain('10×10 ·');
        expect(wrapper.text()).not.toContain('Image conversion failed');
        wrapper.unmount();
    });
});
