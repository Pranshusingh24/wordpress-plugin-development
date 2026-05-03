/**
 * contactApi.js  —  components/contact/
 * Single cached fetch for the entire Contact page ACF data.
 * All contact section components share this one call — WP is hit only once.
 *
 * WordPress setup:
 *   Page ID : 18
 *   ACF Group field name: contact_page
 *
 * ACF structure (inside contact_page group):
 *
 *   banner (Group)
 *     ├── title                     → Text
 *     └── discerption               → Text Area
 *
 *   form_section (Group)
 *     ├── badge_text                → Text
 *     ├── heading                   → Text
 *     ├── discerption               → Text Area   (WP typo for "description")
 *     └── form_card (Group)
 *           ├── form_title              → Text
 *           ├── form_sub_title          → Text Area
 *           ├── form_image              → Image
 *           └── contact_form_shortcode  → Text Area
 *
 *   info_section (Group)
 *     ├── heading          → Text
 *     ├── sub_heading      → Text Area
 *     └── contact_items    → Repeater
 *           ├── icon_type  → Select (email | phone | address)
 *                icon       → Icon Picker
 *           ├── label      → Text
 *           ├── value      → Text
 *           └── link       → Text
 */

export const WP_BASE =
  (typeof import.meta !== "undefined" && import.meta.env?.VITE_WP_REST_URL) ||
  "https://darkred-worm-224502.hostingersite.com/wp-json";

export const CONTACT_PAGE_ID = 18;
export const CONTACT_ACF_KEY = "contact_page";

/* ── Resolve any ACF image field → URL string ── */
export async function resolveImg(val) {
  if (!val) return "";
  if (typeof val === "object" && val.type === "media_library" && val.value)
    val = val.value;
  if (typeof val === "object" && val.url) return val.url;
  if (
    typeof val === "number" ||
    (typeof val === "string" && /^\d+$/.test(val))
  ) {
    try {
      const r = await fetch(
        `${WP_BASE}/wp/v2/media/${val}?_fields=source_url`
      );
      const d = await r.json();
      return d.source_url || "";
    } catch {
      return "";
    }
  }
  return typeof val === "string" ? val : "";
}

/* ── Module-level cache ── */
let _cache   = null;
let _promise = null;

/**
 * fetchContactPage()
 * Returns the full acf object for the contact page.
 * Shape:
 * {
 *   form_section: { badge_text, heading, sub_heading, form_title, form_sub_title, form_image },
 *   info_section: { heading, sub_heading, contact_items: [...] },
 *   cf7_form_id:  number | string,
 *   // raw ACF fields also available (contact_email, contact_phone, contact_address)
 * }
 */
export async function fetchContactPage() {
  if (_cache)   return _cache;
  if (_promise) return _promise;

  _promise = fetch(
    `${WP_BASE}/wp/v2/pages/${CONTACT_PAGE_ID}?_fields=acf`
  )
    .then((r) => {
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      return r.json();
    })
    .then((json) => {
      _cache    = json?.acf?.[CONTACT_ACF_KEY] ?? json?.acf ?? {};
      _promise  = null;
      return _cache;
    })
    .catch((err) => {
      _promise = null;
      throw err;
    });

  return _promise;
}

/** Submit a contact form via CF7 REST endpoint */
export async function submitContactForm(formData) {
  const acf = await fetchContactPage().catch(() => ({}));
  const formId = acf?.cf7_form_id || 1;

  const body = new URLSearchParams();
  Object.entries(formData).forEach(([k, v]) => body.append(k, v));

  const res = await fetch(
    `${WP_BASE}/contact-form-7/v1/contact-forms/${formId}/feedback`,
    { method: "POST", body }
  );

  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  return res.json();
}
