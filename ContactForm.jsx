/**
 * ContactForm.jsx
 * Self-contained contact form component.
 * Handles state, validation, API submission, success & error UI.
 *
 * API: POST /wp-json/dcf/v1/contact
 * Fields: firstName (required), email (required), phone, subject, message (required)
 */

import { useState } from "react";
import { WP_BASE } from "./contactApi";
import "./ContactForm.css";

const INITIAL_FORM = {
  firstName: "",
  email:     "",
  phone:     "",
  subject:   "",
  message:   "",
};

export default function ContactForm() {
  const [form,   setForm]   = useState(INITIAL_FORM);
  const [status, setStatus] = useState("idle"); // idle | sending | success | error
  const [errors, setErrors] = useState({});

  /* ── Validation ── */
  function validate() {
    const e = {};
    if (!form.firstName.trim()) e.firstName = "First name is required.";
    if (!form.email.trim())     e.email     = "Email is required.";
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email))
                                e.email     = "Enter a valid email.";
    if (!form.message.trim())   e.message   = "Message is required.";
    return e;
  }

  /* ── Field change ── */
  function handleChange(e) {
    const { name, value } = e.target;
    setForm((prev) => ({ ...prev, [name]: value }));
    if (errors[name]) setErrors((prev) => ({ ...prev, [name]: "" }));
  }

  /* ── Submit ── */
  async function handleSubmit(e) {
    e.preventDefault();
    const errs = validate();
    if (Object.keys(errs).length) { setErrors(errs); return; }

    setStatus("sending");

    try {
      const res = await fetch(`${WP_BASE}/dcf/v1/contact`, {
        method:  "POST",
        headers: { "Content-Type": "application/json" },
        body:    JSON.stringify({
          firstName: form.firstName,
          email:     form.email,
          phone:     form.phone,
          subject:   form.subject,
          message:   form.message,
        }),
      });

      if (!res.ok) throw new Error(`HTTP ${res.status}`);

      setStatus("success");
      setForm(INITIAL_FORM);
    } catch {
      setStatus("error");
    }
  }

  /* ── Success screen ── */
  if (status === "success") {
    return (
      <div className="cf__success" role="alert">
        <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" className="cf__success-icon">
          <circle cx="12" cy="12" r="10" stroke="#22c55e" strokeWidth="1.8" />
          <path d="M8 12l3 3 5-5" stroke="#22c55e" strokeWidth="2"
            strokeLinecap="round" strokeLinejoin="round" />
        </svg>
        <h3>Message Sent!</h3>
        <p>Thank you for reaching out. Our team will get back to you shortly.</p>
        <button className="cf__success-reset" onClick={() => setStatus("idle")}>
          Send another message
        </button>
      </div>
    );
  }

  /* ── Form ── */
  return (
    <form className="cf__form" onSubmit={handleSubmit} noValidate aria-label="Contact form">

      {/* Row 1: First Name + Email */}
      <div className="cf__row">
        <div className={`cf__group${errors.firstName ? " cf__group--error" : ""}`}>
          <label htmlFor="cf-firstname" className="cf__label">First Name</label>
          <input
            id="cf-firstname" type="text" name="firstName"
            value={form.firstName} onChange={handleChange}
            className="cf__input" placeholder="Enter your First Name"
            autoComplete="given-name" aria-required="true"
            aria-describedby={errors.firstName ? "cf-firstname-err" : undefined}
          />
          {errors.firstName && (
            <span id="cf-firstname-err" className="cf__error" role="alert">
              {errors.firstName}
            </span>
          )}
        </div>

        <div className={`cf__group${errors.email ? " cf__group--error" : ""}`}>
          <label htmlFor="cf-email" className="cf__label">Email</label>
          <input
            id="cf-email" type="email" name="email"
            value={form.email} onChange={handleChange}
            className="cf__input" placeholder="Enter your Email"
            autoComplete="email" aria-required="true"
            aria-describedby={errors.email ? "cf-email-err" : undefined}
          />
          {errors.email && (
            <span id="cf-email-err" className="cf__error" role="alert">
              {errors.email}
            </span>
          )}
        </div>
      </div>

      {/* Row 2: Phone + Subject */}
      <div className="cf__row">
        <div className="cf__group">
          <label htmlFor="cf-phone" className="cf__label">Phone Number</label>
          <input
            id="cf-phone" type="tel" name="phone"
            value={form.phone} onChange={handleChange}
            className="cf__input" placeholder="Enter your Phone Number"
            autoComplete="tel"
          />
        </div>

        <div className="cf__group">
          <label htmlFor="cf-subject" className="cf__label">Subject</label>
          <input
            id="cf-subject" type="text" name="subject"
            value={form.subject} onChange={handleChange}
            className="cf__input" placeholder="Enter your Subject"
          />
        </div>
      </div>

      {/* Message — full width */}
      <div className={`cf__group${errors.message ? " cf__group--error" : ""}`}>
        <label htmlFor="cf-message" className="cf__label">Your Message</label>
        <textarea
          id="cf-message" name="message"
          value={form.message} onChange={handleChange}
          className="cf__input cf__textarea"
          placeholder="Type your message"
          rows={5} aria-required="true"
          aria-describedby={errors.message ? "cf-message-err" : undefined}
        />
        {errors.message && (
          <span id="cf-message-err" className="cf__error" role="alert">
            {errors.message}
          </span>
        )}
      </div>

      {/* Global submit error */}
      {status === "error" && (
        <p className="cf__submit-error" role="alert">
          Something went wrong. Please try again.
        </p>
      )}

      {/* Submit button — centered */}
      <div className="cf__submit-wrap">
        <button
          type="submit"
          className="cf__submit"
          disabled={status === "sending"}
          aria-busy={status === "sending"}
        >
          {status === "sending" ? (
            <><span className="cf__spinner" aria-hidden="true" /> Sending…</>
          ) : (
            "Submit"
          )}
        </button>
      </div>

    </form>
  );
}
