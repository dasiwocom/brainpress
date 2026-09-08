# JS小白核心笔记：window、作用域、变量逃逸、return、闭包

## 1\. 两个盒子（重中之重）

- **大盒子 window**：浏览器全局盒子，所有页面都能访问

- **小盒子 函数**：每个函数自带一个独立小盒子，默认出函数就销毁

## 2\. 全局变量 和 window 的关系

只有 **var** 定义的全局变量，会放进 window 大盒子。

let / const 全局变量：是全局能用，但**不进 window 盒子**。

```Plain Text
var a = 10    // 进 window
let b = 20    // 不进 window
const c = 30  // 不进 window
```

## 3\. 函数内变量的 3 种情况（最容易考）

### ① 函数内正常声明变量（let/var/const）

变量进 **函数小盒子**，函数执行完直接销毁，外面访问不到。

```Plain Text
function fn(){
  var x = 100
}
fn()
// 外面拿不到 x
```

### ② 函数内 **不声明直接赋值**（变量逃逸）

不会进函数小盒子，**直接进 window 大盒子**，全局都能用。

❌ 坏习惯，禁止使用！

```Plain Text
function fn(){
  y = 200 // 没声明 → 直接挂载 window
}
fn()
console.log(y) // 200
```

### ③ 手动挂载 window

把局部变量的值，复制到 window 上，变成全局属性。

```Plain Text
function fn(){
  var z = 300
  window.z = z
}
fn()
console.log(z) // 300
```

## 4\. return 返回值（正常用法，推荐）

**return 返回的是「值的副本」**

函数执行完毕 → 小盒子直接销毁，原始变量消失。

外面拿到的只是复印件，和里面变量彻底断开。

```Plain Text
function fn(){
  let num = 100
  return num // 只返回 100 这个数值
}
let res = fn()
// num 已经销毁了！
```

## 5\. 闭包 终极通俗理解（你刚才卡的点）

**闭包 = return 返回「内部函数」**

重点：

- return 的 **不是变量**，是函数

- 内部函数 **记住了外层函数的小盒子变量**

- 外层函数跑完，**小盒子不销毁，变量活着**

- 每次执行返回的函数，会 **重新回去读取/修改原来的变量**

```Plain Text
function outer(){
  let num = 100

  // 内部函数记住了 num
  function inner(){
    num++
    console.log(num)
  }

  return inner // 返回函数，不是返回值
}

let fn = outer() 
// outer 已经执行完了，但是 num 没有销毁！

fn() // 101 回去操作原变量
fn() // 102 继续操作原变量
fn() // 103
```

## 6\. return 值 VS 闭包 一句话终极区别

- **return 数值**：复制一份值给你，原变量销毁（一次性）

- **return 函数（闭包）**：给你一把钥匙，原盒子保留，可以反复操作原变量（有记忆）

## 7\. this 和 window 极简总结

- 最外层代码：**this === window**

- window 是固定大盒子

- this 是代词，会变，看调用方式

> （注：部分内容可能由 AI 生成）
