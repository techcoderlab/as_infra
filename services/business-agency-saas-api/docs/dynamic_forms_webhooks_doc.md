# 📄 JSON Schema Documentation for Dynamic Forms & Webhooks

This documentation describes the JSON schema used for creating dynamic forms and configuring system webhooks. It is aligned with the **WebhooksPage.vue** implementation.

---

## 1. Common Properties

These are properties that can be applied to almost any field type in your dynamic form schema.

| Property    | Type     | Required | Description |
|------------|----------|---------|------------|
| `type`     | String   | Yes     | The type of input to render (e.g., text, select, checkbox-group). |
| `name`     | String   | Yes     | Unique identifier for the field. This key is used in form submission and webhooks payloads. |
| `label`    | String   | No      | Human-readable label displayed above the input. |
| `placeholder` | String | No      | Placeholder text displayed inside the input. |
| `hint`     | String   | No      | Helper text displayed next to the label. |
| `required` | Boolean  | No      | If true, the field must be filled or selected before submission. |

---

## 2. Field Types & Examples

### A. Basic Text Inputs

#### Text Field
```json
{
  "type": "text",
  "name": "full_name",
  "label": "Full Name",
  "placeholder": "e.g. John Doe",
  "required": true,
  "min": 2,
  "max": 50
}
```

#### Email Field
```json
{
  "type": "email",
  "name": "work_email",
  "label": "Work Email",
  "placeholder": "john@company.com",
  "required": true
}
```

#### Number Field
```json
{
  "type": "number",
  "name": "age",
  "label": "Age",
  "placeholder": "18",
  "required": true
}
```

#### Textarea
```json
{
  "type": "textarea",
  "name": "message",
  "label": "Your Message",
  "placeholder": "Tell us about your project...",
  "required": true,
  "min": 10,
  "max": 500
}
```

---

### B. Selection Inputs

#### Dropdown / Select
```json
{
  "type": "select",
  "name": "service_type",
  "label": "Service Needed",
  "placeholder": "Choose a service...",
  "options": [
    { "label": "Web Development", "value": "web_dev" },
    { "label": "SEO", "value": "seo" },
    { "label": "Marketing", "value": "marketing" }
  ],
  "required": true
}
```

#### Multi-Select
```json
{
  "type": "select",
  "name": "technologies",
  "label": "Technologies",
  "multiple": true,
  "options": [
    { "label": "Vue.js", "value": "vue" },
    { "label": "React", "value": "react" }
  ]
}
```

#### Radio Group
```json
{
  "type": "radio-group",
  "name": "budget",
  "label": "Estimated Budget",
  "options": [
    { "label": "Under $1k", "value": "low" },
    { "label": "$1k - $5k", "value": "medium" },
    { "label": "$5k+", "value": "high" }
  ],
  "required": true
}
```

#### Checkbox Group
```json
{
  "type": "checkbox-group",
  "name": "contact_preference",
  "label": "How should we contact you?",
  "options": [
    { "label": "Email", "value": "email" },
    { "label": "Phone", "value": "phone" },
    { "label": "Slack", "value": "slack" }
  ]
}
```

---

### C. Advanced Inputs

#### Range Slider
```json
{
  "type": "range",
  "name": "satisfaction",
  "label": "Satisfaction Level",
  "min": 0,
  "max": 10,
  "step": 1
}
```

#### File Upload
```json
{
  "type": "file",
  "name": "attachment",
  "label": "Upload Proposal",
  "multiple": false,
  "required": true
}
```

#### Color Picker
```json
{
  "type": "color",
  "name": "brand_color",
  "label": "Brand Primary Color",
  "value": "#000000"
}
```

#### Progress Bar (Read-Only)
```json
{
  "type": "progress",
  "name": "setup_progress",
  "label": "Setup Completion",
  "value": 75
}
```

---

## 3. Validation Rules

| Property | Supported Types | Description |
|----------|----------------|------------|
| `required` | All | Ensures the field is not empty. For arrays, ensures at least one item is selected. |
| `min` | Text, Textarea | Minimum number of characters. |
| `max` | Text, Textarea | Maximum number of characters. |
| `email format` | Email | Validates standard email addresses automatically. |

**Example: Fully Validated Field**
```json
{
  "type": "textarea",
  "name": "bio",
  "label": "Biography",
  "required": true,
  "min": 50,
  "max": 500,
  "placeholder": "Write at least 50 characters..."
}
```

---

## 4. Webhooks Configuration Schema

The webhooks form in **WebhooksPage.vue** uses the following JSON structure:

| Property | Type | Required | Description |
|---------|------|---------|------------|
| `name`  | String | No | Friendly name for the webhook. |
| `url`   | String | Yes | Target URL where events are sent. |
| `secret` | String | No | Optional secret for signing payloads (HMAC). |
| `events` | Array[String] | Yes | List of event identifiers to subscribe to. |

**Example Webhook JSON**
```json
{
  "name": "Zapier Lead Sync",
  "url": "https://hooks.zapier.com/hooks/catch/123456/abcde",
  "secret": "my-secret",
  "events": [
    "lead.created",
    "lead.updated.status"
  ]
}
```

**Available Events**
```json
[
  { "category": "Leads", "label": "Lead Created", "value": "lead.created" },
  { "category": "Leads", "label": "Lead Status Update", "value": "lead.updated.status" },
  { "category": "Leads", "label": "Lead Temperature Update", "value": "lead.updated.temperature" },
  { "category": "Leads", "label": "Any Lead Update", "value": "lead.updated" }
]
```

---

## 5. Notes & Best Practices

1. **Validation**: The `createWebhook` function ensures that `url` and `events` are provided before submission.
2. **Dynamic UI**: All fields render automatically based on their type, `label`, and `options`.
3. **Extensibility**: New events or field types can be added without modifying Vue component logic.
4. **Error Handling**: API errors are logged and user-friendly alerts are shown.
5. **Reactive Forms**: Uses `reactive()` and `v-model` to bind form data, enabling two-way data binding.

---

This schema can be used to **generate dynamic forms**, **configure webhooks**, and **ensure consistent validation** across your application.

