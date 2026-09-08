# 通俗拆解

> 数组、Date：都是**对象(object)**，你理解的没错，里面存一堆属性。 剩下三个：`NaN`、`null`、`undefined` 单独拆开讲。

## 1. NaN —— 类型是 number

> NaN = Not a Number，翻译：“不是一个数字”

```
let res = 0 / 0;
console.log(res);      // NaN
console.log(typeof res);// "number"
```

### 为什么它明明叫“不是数字”，类型还是 number？

JS里：**NaN 是数字类型里面一个特殊的异常值**。 就好比：整数里面有个特殊值，用来表示“计算出错、得不到有效数字”。

> 类比C语言：`0.0/0.0` 得到 `NaN`，它依然属于 double 浮点类型，不是新类型。

⚠️最坑规则：

```
NaN === NaN // false，NaN不等于自己！
```

判断是不是 NaN，要用 `Number.isNaN(值)`。

---

## 2. null

```
let n = null;
console.log(typeof n); // "object"
```

- **语义上 null：代表“空、什么都没有”，它根本不是对象。**
- `typeof null === "object"` 是 **JS远古历史bug**，语言后来不敢改，一改老网站全部崩掉，就一直保留到现在。

> 记住： 逻辑含义：null 是空值。 typeof输出只是个bug，**千万不要拿 typeof 判断 null**。 ✅正确写法：`val === null`

> C语言对比：C里面 `NULL` 是空指针；JS的null是独立原始值，只是typeof搞错输出object。

---

## 3. undefined

```
let a;       //声明变量，不给赋值
console.log(a); // undefined
console.log(typeof a); // "undefined"
```

含义：**变量已经创建，但是还没有给它赋任何值**。

- `undefined` 是一个独立的原始类型，类型名就叫 `undefined`。
- 和 null 的区别：
    - `null`：人为手动赋值，表示空。`let x = null;`
    - `undefined`：自动出现，没赋值。`let x;`

```
let x = null;    // 我主动设置为空
let y;           // 只声明，没给任何东西 → y是undefined
```

|值|含义|typeof结果|
|---|---|---|
|`NaN`|数字运算出错，无效数字|`number`|
|`null`|手动设置为空（bug：typeof返回object）|`object`（bug）|
|`undefined`|变量存在，但尚未赋值|`undefined`|
|`[]`数组|对象，可以存很多数据|`object`|
|`new Date()`日期|对象，存时间相关属性|`object`|

---

# Markdown笔记片段

```
## NaN / null / undefined 通俗理解

### 1. NaN
- 含义：数字计算失败得到的**无效数字**
- `typeof NaN → "number"`
- 虽然叫“不是数字”，但它仍然属于 number 类型，是数字类型的特殊值
- ⚠️ `NaN === NaN // false`，NaN不等于自己
- ✅ 判断：`Number.isNaN(NaN)`

### 2. null
- 含义：**手动赋值，表示空值**，本身不是对象
- `typeof null → "object"` 是JS历史遗留bug，不要用typeof检测null
- ✅ 判断null：`val === null`

### 3. undefined
- 含义：变量已经声明，**但是没有赋值**，JS自动给的默认值
- `typeof undefined → "undefined"`，独立类型

> null vs undefined
> - null：人主动写出来，表示空
> - undefined：JS自动产生，表示还没赋值

### 数组、Date
- 数组 `[]`、日期 `new Date()` 都是对象 `object`，内部存储一组属性。
- 判断数组不能用typeof，用 `Array.isArray(arr)`
```

### C语言对照快速理解

1. NaN：C语言浮点运算也会产生NaN，仍然属于double类型。
2. null：类似C的NULL空指针，但JS中null不是指针，只是typeof输出有bug。
3. undefined：**C语言没有这个东西**，C局部变量不赋值是随机垃圾值；JS不赋值自动是undefined。

这三个里面，**undefined是C完全没有的概念，需要重点记。**