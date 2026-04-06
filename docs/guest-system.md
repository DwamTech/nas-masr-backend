# نظام الـ Guest — شرح كامل

## ما هو الـ Guest؟

الـ Guest هو أي زائر يفتح التطبيق **بدون تسجيل دخول**.  
يمكنه تصفح الإعلانات والفلاتر والبيانات العامة بالكامل.

---

## الـ Endpoints المتاحة للـ Guest

### 1. الكاتيجريز

```
GET /api/categories
```
يرجع كل الكاتيجريز المتاحة.

---

### 2. الإعلانات — بحث وتصفح

```
GET /api/v1/{section}/listings
```

`{section}` = slug الكاتيجري مثل: `cars`, `real-estate`, `jobs`

#### الفلاتر المتاحة:

| الفلتر | النوع | الوصف |
|--------|-------|-------|
| `q` | string | بحث نصي في العنوان |
| `governorate_id` | integer | فلتر بالمحافظة (ID) |
| `governorate` | string | فلتر بالمحافظة (اسم) |
| `city_id` | integer | فلتر بالمدينة (ID) |
| `city` | string | فلتر بالمدينة (اسم) |
| `price_min` | number | الحد الأدنى للسعر |
| `price_max` | number | الحد الأقصى للسعر |
| `plan_type` | string | نوع الخطة: `free`, `standard`, `featured` |
| `make_id` | integer | الماركة (للسيارات فقط) |
| `make` | string | اسم الماركة (للسيارات فقط) |
| `model_id` | integer | الموديل (للسيارات فقط) |
| `model` | string | اسم الموديل (للسيارات فقط) |
| `main_section_id` | integer | القسم الرئيسي (للأقسام التي تدعمه) |
| `main_section` | string | اسم القسم الرئيسي |
| `sub_section_id` | integer | القسم الفرعي |
| `sub_section` | string | اسم القسم الفرعي |
| `attr[key]` | mixed | فلتر بقيمة محددة لـ attribute |
| `attr_in[key][]` | array | فلتر بقيم متعددة لـ attribute |
| `attr_min[key]` | number | الحد الأدنى لـ attribute رقمي |
| `attr_max[key]` | number | الحد الأقصى لـ attribute رقمي |
| `attr_like[key]` | string | بحث نصي جزئي في attribute |

#### مثال:
```
GET /api/v1/cars/listings?make_id=5&price_min=50000&price_max=200000&governorate_id=1
```

---

### 3. تفاصيل إعلان

```
GET /api/v1/{section}/listings/{id}
```

يرجع تفاصيل الإعلان كاملة + بيانات صاحب الإعلان.

---

### 4. بحث عام (Global Search)

```
GET /api/listings/search?q={keyword}
```

بحث في كل الإعلانات بغض النظر عن الكاتيجري.

---

### 5. المعلنون المميزون

```
GET /api/the-best/{section}
```

يرجع المعلنين المميزين في قسم معين مرتبين حسب الـ `rank`.

---

### 6. المحافظات والمدن

```
GET /api/governorates
GET /api/governorates/{governorate}/cities
```

---

### 7. الماركات والموديلات (للسيارات)

```
GET /api/makes
GET /api/makes/{make}/models
```

---

### 8. الأقسام الرئيسية والفرعية

```
GET /api/main-sections
GET /api/sub-sections/{mainSection}
```

---

### 9. حقول الكاتيجري (الفلاتر الديناميكية)

```
GET /api/category-fields
```

يرجع كل الحقول الديناميكية لكل كاتيجري — يُستخدم لبناء الفلاتر في الـ UI.

---

### 10. إعلانات مستخدم معين

```
GET /api/users/{user}
```

يرجع بيانات المستخدم + إعلاناته العامة.

---

### 11. البانرات

```
GET /api/banners
```

---

### 12. إعدادات النظام

```
GET /api/system-settings
```

---

### 13. أسعار الخطط

```
GET /api/plan-prices?category_id={id}
```

---

### 14. تتبع النقر على التواصل

```
POST /api/v1/{section}/listings/{id}/contact-click
```

يُسجّل نقرة تواصل على إعلان (بدون تسجيل دخول).

---

### 15. الـ Guest FCM Token

#### POST /api/guest/fcm-token
يُسجّل أو يُحدّث الـ FCM token للـ Guest عشان يستقبل إشعارات.

**Body (JSON):**
```json
{
    "guest_uuid": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
    "fcm_token": "token_from_firebase"
}
```

**الـ guest_uuid:** معرّف فريد يولّده الـ Frontend ويحتفظ به محلياً (مثل UUID v4).

**كيف يولّد الـ Frontend الـ guest_uuid؟**

الباك يقبل أي string بحد أقصى 255 حرف، لكن الصيغة المعتمدة هي **UUID v4**.

```javascript
// JavaScript — توليد UUID v4
function generateUUID() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
        const r = Math.random() * 16 | 0;
        const v = c === 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
}

// أو في Flutter/Dart
// import 'package:uuid/uuid.dart';
// final uuid = Uuid().v4();
```

**الصيغة المطلوبة:**
```
xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
مثال: 550e8400-e29b-41d4-a716-446655440000
```

**قاعدة مهمة:** يولّده الـ Frontend **مرة واحدة فقط** عند أول تشغيل للتطبيق ويحفظه في `SharedPreferences` أو `localStorage` — لا يتغير إلا لو المستخدم مسح بيانات التطبيق.

**الحالات:**

| الحالة | الكود | الرد |
|--------|-------|------|
| الـ guest_uuid موجود مسبقاً | 200 | تم تحديث الـ FCM token |
| الـ guest_uuid جديد | 201 | تم إنشاء مستخدم ضيف جديد |

**Response (200 — موجود مسبقاً):**
```json
{
    "message": "تم العثور على المستخدم وتحديث FCM token بنجاح.",
    "guest_uuid": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
    "fcm_token": "token_from_firebase"
}
```

**Response (201 — جديد):**
```json
{
    "message": "تم إنشاء مستخدم ضيف جديد بنجاح.",
    "guest_uuid": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
    "fcm_token": "token_from_firebase"
}
```

---

#### GET /api/guest/fcm-token
يجيب بيانات الـ Guest بناءً على الـ `guest_uuid`.

**Query Param:**
```
GET /api/guest/fcm-token?guest_uuid=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
```

**Response (200):**
```json
{
    "id": 42,
    "guest_uuid": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
    "fcm_token": "token_from_firebase"
}
```

**Response (404 — مش موجود):**
```json
{
    "message": "لم يتم العثور على المستخدم الضيف."
}
```

---

## كيفية استخدام الفلاتر الديناميكية

الفلاتر الديناميكية بتيجي من endpoint الـ `category-fields` — كل حقل عنده `filterable: true` يمكن استخدامه كفلتر.

### أنواع الفلاتر وطريقة الاستخدام:

#### 1. فلتر بقيمة محددة `attr[key]`
```
GET /api/v1/cars/listings?attr[fuel_type]=petrol
```
يجيب الإعلانات اللي `fuel_type = petrol` بالظبط.

---

#### 2. فلتر بقيم متعددة `attr_in[key][]`
```
GET /api/v1/cars/listings?attr_in[fuel_type][]=petrol&attr_in[fuel_type][]=diesel
```
يجيب الإعلانات اللي `fuel_type` إما `petrol` أو `diesel`.

---

#### 3. فلتر نطاق رقمي `attr_min[key]` و `attr_max[key]`
```
GET /api/v1/cars/listings?attr_min[year]=2018&attr_max[year]=2023
```
يجيب السيارات من سنة 2018 لـ 2023.

---

#### 4. بحث نصي جزئي `attr_like[key]`
```
GET /api/v1/jobs/listings?attr_like[job_title]=مهندس
```
يجيب الإعلانات اللي `job_title` يحتوي على كلمة "مهندس".

---

### مثال شامل (سيارات):
```
GET /api/v1/cars/listings
    ?make_id=5
    &attr_in[fuel_type][]=petrol&attr_in[fuel_type][]=hybrid
    &attr_min[year]=2020
    &attr_max[year]=2024
    &price_min=100000
    &price_max=500000
    &governorate_id=1
```

---

## نظام الإشعارات للـ Guest

### كيف يشتغل؟

1. الـ Frontend يولّد `guest_uuid` مرة واحدة ويحفظه محلياً
2. عند فتح التطبيق يبعت `POST /api/guest/fcm-token` بالـ `guest_uuid` + `fcm_token`
3. الباك يحفظ الـ Guest في جدول `users` بـ `role = guest`
4. لما يحصل حدث (إعلان جديد، عرض، إلخ) الباك يبعت إشعار Firebase للـ `fcm_token` المحفوظ

### فايدة الـ `id` اللي بيرجعه GET

```json
{
    "id": 42,
    "guest_uuid": "...",
    "fcm_token": "..."
}
```

الـ `id` هو الـ `user_id` الحقيقي في قاعدة البيانات.

**يُستخدم في:**
- إرسال رسائل الـ Chat — الـ Chat system يحتاج `user_id` مش `guest_uuid`
- تتبع الإشعارات المرسلة لمستخدم معين
- ربط الـ Guest بحسابه لو سجّل لاحقاً

**متى يستدعي الـ Frontend الـ GET؟**
- لو التطبيق عنده `guest_uuid` محفوظ لكن مش عنده الـ `id`
- للتحقق إن الـ Guest لسه موجود في الباك قبل إرسال رسالة Chat

---

## ملاحظات مهمة

- كل الـ endpoints أعلاه **لا تحتاج token**.
- الـ Guest يرى الإعلانات بحالة `Valid` فقط.
- الإعلانات مرتبة حسب `rank ASC` (الأقل rank يظهر أولاً).
- الفلاتر الديناميكية تعمل فقط على الحقول التي `filterable = true` في الكاتيجري.
- الـ `guest_uuid` يولّده الـ Frontend مرة واحدة ويحتفظ به في الـ local storage.
- الـ Chat متاح للـ Guest عبر `guest_uuid` header (بدون تسجيل دخول).
