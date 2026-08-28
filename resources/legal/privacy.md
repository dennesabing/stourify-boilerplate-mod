Stourify is a mobile app for finding, photographing and reviewing places. This policy explains what
the app collects, why, where it goes, and how to get rid of it.

It is written to describe the software as it is actually built, not as a generic template imagines
it. Where it says the app does not do something, that is a statement about the code.

## 1. Who is responsible

**[LEGAL ENTITY NAME]** ("we", "us") operates Stourify.

- Registered address: **[REGISTERED ADDRESS]**
- Privacy contact: **[PRIVACY CONTACT EMAIL]**
- Data protection contact, where one is required: **[DATA PROTECTION CONTACT]**

## 2. What we collect

### 2.1 Account information

When you register we ask for three things only:

| Field | Why |
|---|---|
| Name | Shown on your profile and on the content you publish |
| Email address | Sign-in, password reset, and service notices |
| Password | Stored only as a salted one-way hash — we never hold the password itself |

### 2.2 Profile information you choose to add

All optional, all editable, all visible to other users unless your account is private:

- Username (your public `@handle`)
- Short bio (up to 150 characters)
- Website link
- Home city
- Interests
- Profile photo
- Contact number and date of birth, if you fill them in
- A default location for your account, including its coordinates

### 2.3 Things you create

- **Spots** — a place you add: title, description, address, **latitude and longitude**, categories, opening hours
- **Posts** — a caption and photos attached to a spot, with a visibility setting
- **Reviews** — a rating from 1 to 5 and review text
- **Comments** and **likes** on other people's content
- **Wishlist entries**, including any private note you attach to one
- **Photos** you capture or select

### 2.4 Precise location

The app uses your device's location, **while you are using the app only**.

- It requests **foreground** location permission (`ACCESS_FINE_LOCATION`, `ACCESS_COARSE_LOCATION`).
- It does **not** request background location, does not track you when the app is closed, and
  contains no geofencing or continuous location monitoring.
- Your current position is used to answer one question — *which spots are near me* — and is sent to
  our server as a query for that search. **We do not store your device's own position** as a record
  of where you have been.
- Coordinates you attach to a **spot you create are stored and published**, because a place's
  location is the point of the entry. Your profile has a *"show location on spots"* setting that
  controls whether your spots are associated with you publicly.

### 2.5 Camera and photos

The app requests camera access (`CAMERA`) and access to your photo library (`READ_MEDIA_IMAGES`) so
you can attach images to spots and posts. It only reads the images you actually select or capture.

**Photo metadata is removed on your device before anything is uploaded.** A camera writes hidden
information into a photo file — the time the picture was taken, the make and model of the phone, and,
if you had location services on, the exact coordinates you were standing on. None of that is visible
when you look at the picture, and for photos taken at home it can be the most sensitive thing about
them. That hidden information is called EXIF metadata. The app strips it out on your phone, before
the file leaves it — so the coordinates are never sent to us, never stored on our servers, and never
present in the publicly accessible image file.

> **What this does not cover.** The removal applies to JPEG photos, which is what the camera produces
> and what nearly every photo in your gallery is. Two things are **not** stripped yet: image files in
> other formats (such as PNG or HEIC), and **videos**, which can carry coordinates of their own. If
> you are uploading either of those and the location matters to you, remove the metadata yourself
> first.

### 2.6 Technical information

- **Authentication tokens** — issued when you sign in, and stored on your device in the operating
  system's secure keystore.
- **Web session records** — when the web interface is used, the server records the IP address and
  browser user-agent of the session.
- **Administrative activity logs** — significant account events, such as an account deletion, are
  logged so we can answer questions about what happened to an account.

### 2.7 What we do NOT collect

This is a deliberately short app, and the following is verifiable in the source:

- **No analytics or product-tracking SDK.** No Google Analytics, Firebase, Segment, Mixpanel,
  Amplitude, PostHog or equivalent.
- **No crash-reporting or telemetry SDK.** No Sentry, Crashlytics or Bugsnag.
- **No advertising, no ad identifiers, no ad networks, no tracking across other apps or websites.**
- **No device identifiers** — no advertising ID, no installation ID, no device fingerprint.
- **No push notifications and no push tokens.** The app has no notification system at all.
- **No third-party sign-in.** No Google, Apple or Facebook login — so no data is exchanged with
  those providers.
- **No contacts, no microphone, no calendar, no SMS, no call logs, no health data, no payment or
  financial data.**
- **We do not sell your personal data, and we do not share it with data brokers.**

## 3. Where the data is kept

- **Application data** (accounts, spots, posts, reviews, follows) is stored in our application
  database, hosted at **[HOSTING REGION / PROVIDER]**.
- **Photos and other media** are stored on **DigitalOcean Spaces** object storage in the
  **Singapore (sgp1)** region and served through its content delivery network. Uploads go directly
  from your device to that storage using a short-lived signed link.
- **Media files are stored with public visibility**, which means that anyone who has the file's URL
  can open it without signing in. Treat any photograph you upload as public.
- **On your own device**, the app keeps a local copy of your data so it works offline: spots,
  reviews, wishlist items, follows, profiles and city data, plus a private copy of any photo that is
  still waiting to upload. Signing out erases this local database.

## 4. Why we process it, and on what basis

| Purpose | Basis |
|---|---|
| Creating and running your account | Performance of our agreement with you |
| Publishing the content you choose to publish | Performance of our agreement with you |
| Showing nearby spots | Your consent, given through the location permission prompt |
| Attaching photos you select | Your consent, given through the camera and photo permission prompts |
| Keeping the service secure, and handling abuse reports | Our legitimate interest in a safe service |
| Meeting legal obligations | Legal obligation |

Where the law of your country gives you these rights, the legal bases above are those of the
**[APPLICABLE DATA PROTECTION REGIME, e.g. GDPR]**.

## 5. Who can see it

- **Other users** see your public profile and anything you publish. A private account limits this to
  followers you have approved.
- **Nobody else, commercially.** We do not sell, rent or trade personal data.
- **Our infrastructure providers** process data on our behalf in order to run the service — the
  hosting provider at **[HOSTING REGION / PROVIDER]** and DigitalOcean for media storage.
- **Moderators.** When you report content, your report — including your identity as the reporter —
  is visible to whoever reviews the moderation queue. **It is not shown to the person you reported.**
- **Authorities**, where we are legally required to disclose.

## 6. Blocking and reporting

- **Blocking** a user hides their content from you and yours from them. We keep a record of the
  block so it can be enforced; the app deliberately provides no way for anyone to discover who has
  blocked them.
- **Reporting** content or a user creates a record containing your identity, what you reported and
  why. We keep resolved reports so that repeat behaviour can be recognised.

## 7. Deleting your account

You can delete your account yourself, in the app, at **Settings → Delete account**. You will be
asked for your email address and password to confirm it, because the action cannot be undone from
the app. There is also a web form at **[APP URL]/account-deletion** if you no longer have the app
installed.

**Immediately on deletion:**

- every sign-in token is revoked and the account is disabled;
- your spots, posts and reviews are withdrawn from the service;
- your wishlist, your explorer profile and every follow relationship — in both directions — are
  erased outright;
- the local database on your device is wiped.

**Then, after 18 months, everything remaining is permanently erased** from our systems, including
the underlying account record and any content still retained with it.

> **One consequence worth knowing before you delete:** your email address stays reserved for that
> 18-month period. **You cannot re-register with the same email address until the 18 months have
> passed.** If you think you may want to come back, that is a reason to keep the account rather than
> delete it.

Backups and moderation records may persist a little beyond these points where we are required to
keep them; they are overwritten on their own cycle.

## 8. Your rights

Subject to your local law, you may ask us to give you a copy of your data, correct it, delete it,
restrict or object to how we use it, or transfer it elsewhere. Deletion is available immediately in
the app; for anything else, write to **[PRIVACY CONTACT EMAIL]** and we will respond within the
period the applicable law requires.

You may also complain to your local data protection authority.

## 9. Children

Stourify is not intended for children under **[MINIMUM AGE]**, and the terms of service say so as a
rule of the service.

**We do not check anyone's age, and this section says so rather than implying otherwise.** Signing up
asks for a name, an email address and a password — see section 2.1 — and nothing anywhere in the app
asks for a date of birth or an age. We hold no information at all about your age, so we simply
cannot tell how old any account holder is.

The practical consequence is that we cannot spot an under-age account by ourselves. What we can do is
act on being told. If you believe a child has created an account, contact
**[PRIVACY CONTACT EMAIL]** and we will remove it.

## 10. Security

Passwords are stored only as hashes. Sign-in tokens live in your device's secure keystore. Traffic
between the app and our servers uses HTTPS. No system is perfectly secure, and photographs you
publish are, by design, publicly reachable.

## 11. Changes to this policy

We will update the "last updated" date at the top when this policy changes, and will tell you about
significant changes through the app.

## 12. Contact

**[LEGAL ENTITY NAME]** — **[PRIVACY CONTACT EMAIL]** — **[REGISTERED ADDRESS]**
