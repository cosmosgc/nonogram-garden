export type GridSnapshot = { width: number; height: number; cells: number[] };

function copy(snapshot: GridSnapshot): GridSnapshot {
    return { width: snapshot.width, height: snapshot.height, cells: [...snapshot.cells] };
}

function equal(a: GridSnapshot, b: GridSnapshot): boolean {
    return a.width === b.width && a.height === b.height
        && a.cells.length === b.cells.length
        && a.cells.every((value, index) => value === b.cells[index]);
}

export class GridHistory {
    private past: GridSnapshot[] = [];
    private future: GridSnapshot[] = [];

    constructor(private readonly limit = 100) {}

    record(before: GridSnapshot, after: GridSnapshot): void {
        if (equal(before, after)) return;
        this.past.push(copy(before));
        if (this.past.length > this.limit) this.past.shift();
        this.future = [];
    }

    undo(current: GridSnapshot): GridSnapshot | null {
        const previous = this.past.pop();
        if (!previous) return null;
        this.future.push(copy(current));
        return copy(previous);
    }

    redo(current: GridSnapshot): GridSnapshot | null {
        const next = this.future.pop();
        if (!next) return null;
        this.past.push(copy(current));
        return copy(next);
    }

    clear(): void {
        this.past = [];
        this.future = [];
    }

    get canUndo(): boolean { return this.past.length > 0; }
    get canRedo(): boolean { return this.future.length > 0; }
}
