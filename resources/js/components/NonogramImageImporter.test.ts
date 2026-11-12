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
        expect(wrapper.text()).toContain('privately saves both the original');
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
        expect(wrapper.find('[role="img"]').exists()).toBe(false);
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

    it('previews color and standard variants and submits the selected encoding with its images', async () => {
        document.body.innerHTML = '<div id="nonogram-image-importer" data-draft-store-url="/Nonogram%20laravel/public/puzzles/drafts" data-editor-edit-url-template="/Nonogram%20laravel/public/editor/__PUZZLE_ID__"></div>';
        vi.stubGlobal('Worker', undefined);
        vi.stubGlobal('createImageBitmap', vi.fn().mockResolvedValue({ width: 3, height: 1, close: vi.fn() }));
        vi.stubGlobal('ImageData', class { constructor(public data: Uint8ClampedArray, public width: number, public height: number) {} });
        vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockReturnValue({
            drawImage: vi.fn(), putImageData: vi.fn(),
            getImageData: () => ({ data: new Uint8ClampedArray([255, 0, 0, 255, 0, 0, 255, 255, 255, 255, 255, 255]) }),
        } as unknown as CanvasRenderingContext2D);
        vi.spyOn(HTMLCanvasElement.prototype, 'toBlob').mockImplementation(callback => callback(new Blob(['png'], { type: 'image/png' })));
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:color') });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });
        const fetchMock = vi.fn().mockResolvedValue({ ok: false, status: 422, json: async () => ({ message: 'Please try again.' }) });
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(NonogramImageImporter);
        const input = wrapper.get('input[type="file"]');
        Object.defineProperty(input.element, 'files', { configurable: true, value: [new File([new Uint8Array([137, 80, 78, 71, 13, 10, 26, 10])], 'color.png', { type: 'image/png' })] });
        await input.trigger('change');
        await flushPromises();
        await wrapper.get('[data-import-mode]').setValue('color');
        await wrapper.findAll('input[type="number"]')[0].setValue(3);
        await wrapper.findAll('input[type="number"]')[1].setValue(1);
        const button = (text: string) => wrapper.findAll('button').find(item => item.text() === text)!;
        await button('Update preview now').trigger('click');

        expect(wrapper.get('[role="img"]').attributes('aria-label')).toBe('Generated 3 by 1 color grid');
        expect(wrapper.get('[aria-label="Generated color palette"]').text()).toContain('#FF0000');
        await button('Analyze uniqueness').trigger('click');
        expect(wrapper.text()).toContain('Client uniqueness result: unique.');
        await button('Create draft and edit').trigger('click');
        await flushPromises();
        expect(fetchMock.mock.calls[0][0]).toBe('/Nonogram%20laravel/public/puzzles/drafts');
        const colorForm = fetchMock.mock.calls[0][1].body as FormData;
        expect(colorForm.get('grid[mode]')).toBe('color');
        expect(colorForm.get('grid[rules_version]')).toBe('1');
        expect(colorForm.get('grid[palette][0][hex]')).toMatch(/^#[0-9A-F]{6}$/);
        expect(colorForm.get('source_image')).toBeInstanceOf(File);
        expect(colorForm.get('reveal_image')).toBeInstanceOf(File);
        expect(wrapper.get('[role="alert"]').text()).toBe('Please try again.');

        await wrapper.get('[data-standard-version]').setValue(true);
        expect(wrapper.get('[role="img"]').attributes('aria-label')).toBe('Generated 3 by 1 monochrome grid');
        expect(wrapper.text()).not.toContain('Client uniqueness result: unique.');
        await button('Create draft and edit').trigger('click');
        await flushPromises();
        const standardForm = fetchMock.mock.calls[1][1].body as FormData;
        expect(standardForm.get('grid[version]')).toBe('1');
        expect(standardForm.get('grid[cells]')).toBe('110');
        expect(standardForm.has('grid[palette][0][hex]')).toBe(false);
        await wrapper.get('[data-standard-version]').setValue(false);
        expect(wrapper.get('[aria-label="Generated color palette"]').text()).toContain('#FF0000');
        wrapper.unmount();
    });

    it('invalidates stale previews and ignores results after settings change, cancellation, or timeout', async () => {
        vi.useFakeTimers();
        class PendingConversion {
            static instances: PendingConversion[] = [];
            onmessage: ((event: MessageEvent) => void) | null = null;
            onerror: ((event: ErrorEvent) => void) | null = null;
            posted: { id: number } | null = null;
            constructor(readonly url: URL) { PendingConversion.instances.push(this); }
            postMessage(message: { id: number }) {
                this.posted = message;
                if (this.url.href.includes('image-decode')) this.onmessage?.({ data: { id: message.id, raster: { width: 1, height: 1, data: new Uint8ClampedArray([0, 0, 0, 255]) } } } as MessageEvent);
            }
            terminate() {}
            deliver() { this.onmessage?.({ data: { id: this.posted?.id, grid: { version: 1, width: 1, height: 1, cells: '1' } } } as MessageEvent); }
        }
        vi.stubGlobal('Worker', PendingConversion);
        Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:pending') });
        Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });
        const wrapper = mount(NonogramImageImporter);
        const input = wrapper.get('input[type="file"]');
        Object.defineProperty(input.element, 'files', { value: [new File(['png'], 'test.png', { type: 'image/png' })] });
        await input.trigger('change');
        await flushPromises();
        await vi.advanceTimersByTimeAsync(180);
        PendingConversion.instances[1].deliver();
        await flushPromises();
        expect(wrapper.find('[role="img"]').exists()).toBe(true);

        await wrapper.findAll('input[type="number"]')[0].setValue(2);
        expect(wrapper.find('[role="img"]').exists()).toBe(false);
        PendingConversion.instances[1].deliver();
        await flushPromises();
        expect(wrapper.find('[role="img"]').exists()).toBe(false);
        await vi.advanceTimersByTimeAsync(180);
        await wrapper.findAll('button').find(button => button.text() === 'Cancel conversion')!.trigger('click');
        PendingConversion.instances[2].deliver();
        await flushPromises();
        expect(wrapper.find('[role="img"]').exists()).toBe(false);
        expect(wrapper.findAll('button').some(button => button.text() === 'Create draft and edit')).toBe(false);
        await wrapper.findAll('button').find(button => button.text() === 'Update preview now')!.trigger('click');
        await vi.advanceTimersByTimeAsync(20_000);
        expect(wrapper.get('[role="alert"]').text()).toContain('timed out');
        PendingConversion.instances[3].deliver();
        await flushPromises();
        expect(wrapper.find('[role="img"]').exists()).toBe(false);
        wrapper.unmount();
    });
});
