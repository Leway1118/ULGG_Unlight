export class RaisingRepository {
  constructor(storage, key = 'ulgg.raising.prototype.v3') {
    this.storage = storage;
    this.key = key;
  }

  load() {
    try {
      const raw = this.storage.getItem(this.key);
      if (!raw) return null;
      const state = JSON.parse(raw);
      return state?.version === 3 ? state : null;
    } catch {
      return null;
    }
  }

  save(state) {
    this.storage.setItem(this.key, JSON.stringify(state));
  }

  clear() {
    this.storage.removeItem(this.key);
  }
}
