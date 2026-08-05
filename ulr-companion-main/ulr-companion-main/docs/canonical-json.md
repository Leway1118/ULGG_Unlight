# Canonical JSON 實作指南

給要在**其他語言**（ULGG 的 PHP、工具腳本的 Python）實作同一套正規化的人。

規則的身分是它的 `contentHash`。兩邊算出來的 Hash 只要差一個位元，Room
一致性檢查就會全部判成 MISMATCH。所以這份文件的每一條都得照做。

---

## 規範

**RFC 8785 — JSON Canonicalization Scheme (JCS)**

規格書 §5.2 要求的四件事（鍵值排序、UTF-8、消除非語義空白、統一數值表示）
正是 JCS 的定義，所以直接採標準，不自訂格式。

```
規則 JSON → Schema 驗證 → Canonical JSON → SHA-256 → contentHash
```

`contentHash` 表示成 `sha256:` + 64 碼小寫 hex。

---

## 驗收方式

不用讀 TypeScript。載入這個檔案就好：

```
packages/rule-schema/test-vectors/canonical.json
```

```jsonc
{
  "cases": [
    {
      "name": "integral-float",
      "why": "為什麼這題容易錯",
      "input": { "teamCostLimit": 62.0, "cost": 21.0, "half": 18.5 },
      "canonical": "{\"cost\":21,\"half\":18.5,\"teamCostLimit\":62}",
      "hash": "sha256:7c59b369…",
    },
  ],
}
```

對每個 case：把 `input` 丟進你的實作 → 字串必須**完全等於** `canonical`
→ 其 UTF-8 bytes 的 SHA-256 必須等於 `hash`。全部通過就對得上了。

---

## 四個最容易錯的地方

### 1. 整數值的浮點數 ⚠ 最重要

JCS 用 ECMAScript 的 `Number::toString`，也就是「能 round-trip 的最短表示」。

| 語言                      | `21.0` 的輸出 |     |
| ------------------------- | ------------- | --- |
| JavaScript `String(21.0)` | `21`          | ✅  |
| PHP `json_encode(21.0)`   | `21.0`        | ❌  |
| Python `json.dumps(21.0)` | `21.0`        | ❌  |

COST 表滿滿都是這種值，這題錯了**每一個 Hash 都會錯**。

PHP：

```php
// 整數值的 float 要輸出成不帶小數點的形式
if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 1e21) {
    return (string) (int) $v;
}
```

Python：

```python
if isinstance(v, float) and v.is_integer() and abs(v) < 1e21:
    return str(int(v))
```

### 2. 鍵依 UTF-16 code unit 排序，不是 code point

BMP 以內兩者相同，超出就會分岔。

| 字元                            | Code point | 首個 UTF-16 code unit |
| ------------------------------- | ---------- | --------------------- |
| U+FB33（希伯來 dalet 預組合形） | 64307      | 0xFB33 = 64307        |
| U+1F602（😂）                   | 128514     | 0xD83D = 55357        |

UTF-16 序：emoji 在前。Code point 序：反過來。

Python 的 `sorted()` 是 code point 序，**會錯**。要先轉 UTF-16：

```python
keys.sort(key=lambda k: k.encode("utf-16-be"))
```

PHP 的字串是位元組序列，`ksort()` 預設是 UTF-8 位元組序 —— 也**不對**，
一樣要先轉 UTF-16BE 再比。

> `test-vectors` 裡的 `key-order-utf16-surrogate` 就是專門測這題。

### 3. 非 ASCII 不得跳脫

`{"n":"亞城"}`，不是 `{"n":"亞城"}`。

PHP 要加 `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`。
Python 要 `ensure_ascii=False`。

字串跳脫只做 JSON 必要的那些：`"` `\` 與控制字元（`\b` `\f` `\n` `\r` `\t`，
其餘用 `\u00XX`）。

### 4. 不留任何空白

`{"a":1,"b":2}`，冒號與逗號後面都沒有空格。
PHP 的 `json_encode` 預設就沒有；Python 要 `separators=(",", ":")`。

---

## 其他細節

- `-0` 輸出成 `0`
- 陣列**保持原順序**（不排序），只有物件的鍵排序
- 巢狀物件遞迴排序
- `NaN` / `Infinity` 不是合法 JSON —— 應該**丟錯**，不要輸出 `null`。
  COST 欄位被靜默換成 `null` 是災難等級的錯誤。
- 指數形式的門檻跟著 ES6：`1e21` 以上、`1e-7` 以下才用指數。
  `test-vectors` 的 `exponent-forms` 有測。

---

## 我們額外加的限制：COST 最多兩位小數

規則裡的 COST 值最多兩位小數（0.01 精度），且不得用指數形式。

**這條跟 Hash 無關** —— `21.35` 解析成 double 再序列化回來永遠是 `"21.35"`，
JCS 的來回是確定性的。這條限制是為了**加總**：`8.8 + 26.6 + 26.6` 在 IEEE 754
下等於 `62.00000000000001`，拿去跟上限 62 比大小，剛好卡滿的隊伍會被誤判超標。

所以引擎內部一律換成**整數的百分之一**（centi-COST）再運算。

### 給實作者的兩個提醒

**1. 不要用 `multipleOf: 0.01` 做驗證。**
JSON Schema 的 `multipleOf` 實作是 `value / 0.01` 再判斷是否整數，而
`4.35 / 0.01 === 434.99999999999994` —— 完全合法的值會被誤判。
改成檢查十進位表示的小數位數，那是精確的：

```php
// PHP：從 canonical 表示法取小數位數，不做浮點運算
$s = canonical_number($v);          // 就是進 Hash 的那個字串
if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) { /* 拒絕 */ }
```

**2. 轉整數不要用 `n * 100`。**
`21.35 * 100 === 2134.9999999999998`。要從十進位字串取數字：

```php
preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/', $s, $m);
$centi = ($m[1] === '-' ? -1 : 1) * ((int)$m[2] * 100 + (int)str_pad($m[3] ?? '', 2, '0'));
```

`@ulr/rule-schema` 匯出的 `toCentiCost` / `fromCentiCost` / `sumCentiCost`
就是這件事的 TypeScript 版，可以對照。
