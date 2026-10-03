export function normalizeSeed(seed) {
  const text = String(seed ?? 'unlight');
  let hash = 2166136261;
  for (let index = 0; index < text.length; index += 1) {
    hash ^= text.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  return hash >>> 0 || 0x6d2b79f5;
}

export class SeededRandom {
  constructor(seed) {
    this.state = normalizeSeed(seed);
  }

  next() {
    let value = this.state;
    value ^= value << 13;
    value ^= value >>> 17;
    value ^= value << 5;
    this.state = value >>> 0;
    return this.state / 4294967296;
  }

  int(min, max) {
    return Math.floor(this.next() * (max - min + 1)) + min;
  }

  pick(items) {
    return items[this.int(0, items.length - 1)];
  }

  shuffle(items) {
    const copy = [...items];
    for (let index = copy.length - 1; index > 0; index -= 1) {
      const target = this.int(0, index);
      [copy[index], copy[target]] = [copy[target], copy[index]];
    }
    return copy;
  }

  snapshot() {
    return this.state >>> 0;
  }
}
