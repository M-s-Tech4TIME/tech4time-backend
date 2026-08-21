<?php
/**
 * Tech4TIME — careers page.
 *
 * The one page on this site that is not a static file, because it is the one
 * page whose content changes on its own schedule. Job posts live in
 * content/careers.json and are edited through /admin/; this renders them.
 *
 * Rendered on the SERVER, not fetched in the browser. A careers page whose
 * listings arrive by JavaScript is one that search engines index unreliably,
 * and an unindexed job post is an unfilled role.
 *
 * If the data file is missing or unreadable, careers_load() returns an empty
 * set and the page renders its "no openings" state — wrong, but still a page
 * a visitor can act on.
 */

declare(strict_types=1);

require __DIR__ . '/../../lib/careers.php';

$data   = careers_load();
$jobs   = careers_open_jobs($data);
$cvForm = trim((string)($data['cv_form_url'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Careers | Tech4TIME</title>
<meta name="description" content="Open roles at Tech4TIME in cybersecurity, software and infrastructure. See what is available now, or send us your CV for the roles we open next.">
<link rel="canonical" href="https://tech4time.bd/pages/careers/">

<!-- Crawling. Large image previews and full snippets are allowed so rich
     results can use the branded share card. -->
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">

<!-- Security. NOTE: X-Frame-Options and X-Content-Type-Options are ignored in
     <meta> by every browser — they are set for real in .htaccess, which is the
     authoritative source. Referrer-Policy and CSP genuinely do work here, and
     are kept as defence in depth in case the host strips response headers. -->
<meta name="referrer" content="strict-origin-when-cross-origin">
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:locale" content="en_US">
<meta property="og:site_name" content="Tech4TIME">
<meta property="og:title" content="Careers | Tech4TIME">
<meta property="og:description" content="Open roles at Tech4TIME in cybersecurity, software and infrastructure. See what is available now, or send us your CV for the roles we open next.">
<meta property="og:url" content="https://tech4time.bd/pages/careers/">
<meta property="og:image" content="https://tech4time.bd/assets/images/og/tech4time-og.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Tech4TIME — Orchestrating Technology with Time">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Careers | Tech4TIME">
<meta name="twitter:description" content="Open roles at Tech4TIME in cybersecurity, software and infrastructure. See what is available now, or send us your CV for the roles we open next.">
<meta name="twitter:image" content="https://tech4time.bd/assets/images/og/tech4time-og.png">
<meta name="twitter:image:alt" content="Tech4TIME — Orchestrating Technology with Time">

<!-- Icons -->
<link rel="icon" href="/assets/images/favicon/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="16x16" href="/assets/images/favicon/favicon-16.png">
<link rel="icon" type="image/png" sizes="32x32" href="/assets/images/favicon/favicon-32.png">
<link rel="icon" type="image/png" sizes="48x48" href="/assets/images/favicon/favicon-48.png">
<link rel="icon" type="image/png" sizes="96x96" href="/assets/images/favicon/favicon-96.png">
<link rel="apple-touch-icon" sizes="180x180" href="/assets/images/favicon/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#fafafa">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0b0b0c">

<!-- Fonts. Preloaded because the latin subset is on the critical render path;
     the -ext subset is not preloaded since most pages never reference it. -->
<link rel="preload" href="/assets/fonts/inter-latin.woff2" as="font" type="font/woff2" crossorigin>

<!-- Styles, in cascade order -->
<link rel="stylesheet" href="/assets/css/base.css">
<link rel="stylesheet" href="/assets/css/theme.css">
<link rel="stylesheet" href="/assets/css/layout.css">
<link rel="stylesheet" href="/assets/css/components.css">
<link rel="stylesheet" href="/assets/css/animations.css">
<link rel="stylesheet" href="/assets/css/pages/careers.css">

<!-- Colour mode, applied before first paint to avoid a flash of the wrong
     theme. Deliberately NOT deferred; see the comment in the file itself. -->
<script src="/assets/js/theme-init.js"></script>

<!-- Base structured data, identical on every page. Per-page BreadcrumbList and
     any page-specific schema (Service, JobPosting, ContactPage…) go in their own
     block after this one.

     Contact details, addresses and social profiles are taken from the NextJS
     Footer component, which carries the live values. The Organization schema in
     the NextJS root layout lists placeholder social URLs (facebook.com/tech4time,
     twitter.com/tech4time, linkedin.com/company/tech4time, github.com/tech4time)
     that do not match the real profiles the footer links to; the real ones are
     used here. -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@graph": [
    {
      "@type": "Organization",
      "@id": "https://tech4time.bd/#organization",
      "name": "Tech4TIME",
      "alternateName": "M/s. Tech4TIME",
      "url": "https://tech4time.bd/",
      "logo": {
        "@type": "ImageObject",
        "url": "https://tech4time.bd/assets/images/logo/logo-light-540.png",
        "width": 540,
        "height": 192
      },
      "image": "https://tech4time.bd/assets/images/og/tech4time-og.png",
      "description": "Open-Source and enterprise-grade cybersecurity, software development, cloud infrastructure and IT solutions. Orchestrate, build, maintain and protect your business.",
      "slogan": "Orchestrating Technology with Time",
      "foundingDate": "2018-05-15",
      "email": "info@tech4time.bd",
      "areaServed": "Worldwide",
      "knowsLanguage": "en",
      "address": [
        {
          "@type": "PostalAddress",
          "streetAddress": "278/3, Manikdi",
          "addressLocality": "Dhaka",
          "postalCode": "1206",
          "addressCountry": "BD"
        },
        {
          "@type": "PostalAddress",
          "streetAddress": "68100 Batu Caves",
          "addressLocality": "Selangor",
          "addressCountry": "MY"
        },
        {
          "@type": "PostalAddress",
          "streetAddress": "367, Avenue Louise",
          "addressLocality": "Brussels",
          "addressCountry": "BE"
        }
      ],
      "contactPoint": [
        {
          "@type": "ContactPoint",
          "telephone": "+8801320571562",
          "email": "info@tech4time.bd",
          "contactType": "customer service",
          "areaServed": "BD",
          "availableLanguage": ["English"]
        },
        {
          "@type": "ContactPoint",
          "telephone": "+8801881873463",
          "email": "info@tech4time.bd",
          "contactType": "customer service",
          "areaServed": "BD",
          "availableLanguage": ["English"]
        },
        {
          "@type": "ContactPoint",
          "telephone": "+8801847313835",
          "email": "info@tech4time.bd",
          "contactType": "customer service",
          "areaServed": "BD",
          "availableLanguage": ["English"]
        },
        {
          "@type": "ContactPoint",
          "telephone": "+60198527096",
          "email": "info@tech4time.bd",
          "contactType": "customer service",
          "areaServed": "MY",
          "availableLanguage": ["English"]
        }
      ],
      "sameAs": [
        "https://www.linkedin.com/company/tech4time-bd/",
        "https://github.com/M-s-Tech4TIME"
      ]
    },
    {
      "@type": "WebSite",
      "@id": "https://tech4time.bd/#website",
      "url": "https://tech4time.bd/",
      "name": "Tech4TIME",
      "description": "Cybersecurity, software development, cloud infrastructure and HR solutions.",
      "publisher": { "@id": "https://tech4time.bd/#organization" },
      "inLanguage": "en"
    },
    {
      "@type": "ProfessionalService",
      "@id": "https://tech4time.bd/#service",
      "name": "Tech4TIME",
      "url": "https://tech4time.bd/",
      "image": "https://tech4time.bd/assets/images/og/tech4time-og.png",
      "parentOrganization": { "@id": "https://tech4time.bd/#organization" },
      "priceRange": "$$",
      "areaServed": "Worldwide",
      "openingHoursSpecification": [
        {
          "@type": "OpeningHoursSpecification",
          "dayOfWeek": ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday"],
          "opens": "09:00",
          "closes": "18:00",
          "description": "Bangladesh office"
        },
        {
          "@type": "OpeningHoursSpecification",
          "dayOfWeek": ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday"],
          "opens": "09:00",
          "closes": "18:00",
          "description": "Malaysia office"
        }
      ],
      "serviceType": [
        "Cybersecurity Services",
        "Software Development",
        "Cloud Infrastructure",
        "IT Consulting",
        "Managed Services",
        "Human Resources as a Service",
        "DevOps Services",
        "Security Operations Center"
      ],
      "knowsAbout": [
        "Cybersecurity",
        "Penetration Testing",
        "Security Operations Center",
        "Incident Response",
        "Digital Forensics",
        "Software Development",
        "DevSecOps",
        "Cloud Computing",
        "OpenStack",
        "Kubernetes",
        "IT Staffing",
        "HR as a Service"
      ]
    }
  ]
}
</script>

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    {
      "@type": "ListItem",
      "position": 1,
      "name": "Home",
      "item": "https://tech4time.bd/"
    },
    {
      "@type": "ListItem",
      "position": 2,
      "name": "Careers",
      "item": "https://tech4time.bd/pages/careers/"
    }
  ]
}
</script>

<?php /* One JobPosting per open role. This is what puts a post into Google
         Jobs rather than only into ordinary search results. */ ?>
<?php foreach ($jobs as $job): ?>
<script type="application/ld+json">
<?= json_encode(careers_job_posting($job), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>
<?php endforeach; ?>
</head>

<body class="page">
<!-- icon-sprite:start -->
<svg class="icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <symbol id="arrow-right" viewBox="0 0 448 512"><path d="M438.6 278.6c12.5-12.5 12.5-32.8 0-45.3l-160-160c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L338.8 224 32 224c-17.7 0-32 14.3-32 32s14.3 32 32 32l306.7 0L233.4 393.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0l160-160z"/></symbol>
  <symbol id="arrow-up" viewBox="0 0 384 512"><path d="M214.6 41.4c-12.5-12.5-32.8-12.5-45.3 0l-160 160c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L160 141.2V448c0 17.7 14.3 32 32 32s32-14.3 32-32V141.2L329.4 246.6c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3l-160-160z"/></symbol>
  <symbol id="briefcase" viewBox="0 0 512 512"><path d="M184 48H328c4.4 0 8 3.6 8 8V96H176V56c0-4.4 3.6-8 8-8zm-56 8V96H64C28.7 96 0 124.7 0 160v96H192 320 512V160c0-35.3-28.7-64-64-64H384V56c0-30.9-25.1-56-56-56H184c-30.9 0-56 25.1-56 56zM512 288H320v32c0 17.7-14.3 32-32 32H224c-17.7 0-32-14.3-32-32V288H0V416c0 35.3 28.7 64 64 64H448c35.3 0 64-28.7 64-64V288z"/></symbol>
  <symbol id="chevron-right" viewBox="0 0 320 512"><path d="M310.6 233.4c12.5 12.5 12.5 32.8 0 45.3l-192 192c-12.5 12.5-32.8 12.5-45.3 0s-12.5-32.8 0-45.3L242.7 256 73.4 86.6c-12.5-12.5-12.5-32.8 0-45.3s32.8-12.5 45.3 0l192 192z"/></symbol>
  <symbol id="clock" viewBox="0 0 512 512"><path d="M256 0a256 256 0 1 1 0 512A256 256 0 1 1 256 0zM232 120V256c0 8 4 15.5 10.7 20l96 64c11 7.4 25.9 4.4 33.3-6.7s4.4-25.9-6.7-33.3L280 243.2V120c0-13.3-10.7-24-24-24s-24 10.7-24 24z"/></symbol>
  <symbol id="envelope" viewBox="0 0 512 512"><path d="M48 64C21.5 64 0 85.5 0 112c0 15.1 7.1 29.3 19.2 38.4L236.8 313.6c11.4 8.5 27 8.5 38.4 0L492.8 150.4c12.1-9.1 19.2-23.3 19.2-38.4c0-26.5-21.5-48-48-48H48zM0 176V384c0 35.3 28.7 64 64 64H448c35.3 0 64-28.7 64-64V176L294.4 339.2c-22.8 17.1-54 17.1-76.8 0L0 176z"/></symbol>
  <symbol id="file-upload" viewBox="0 0 384 512"><path d="M64 0C28.7 0 0 28.7 0 64V448c0 35.3 28.7 64 64 64H320c35.3 0 64-28.7 64-64V160H256c-17.7 0-32-14.3-32-32V0H64zM256 0V128H384L256 0zM216 408c0 13.3-10.7 24-24 24s-24-10.7-24-24V305.9l-31 31c-9.4 9.4-24.6 9.4-33.9 0s-9.4-24.6 0-33.9l72-72c9.4-9.4 24.6-9.4 33.9 0l72 72c9.4 9.4 9.4 24.6 0 33.9s-24.6 9.4-33.9 0l-31-31V408z"/></symbol>
  <symbol id="github" viewBox="0 0 496 512"><path d="M165.9 397.4c0 2-2.3 3.6-5.2 3.6-3.3.3-5.6-1.3-5.6-3.6 0-2 2.3-3.6 5.2-3.6 3-.3 5.6 1.3 5.6 3.6zm-31.1-4.5c-.7 2 1.3 4.3 4.3 4.9 2.6 1 5.6 0 6.2-2s-1.3-4.3-4.3-5.2c-2.6-.7-5.5.3-6.2 2.3zm44.2-1.7c-2.9.7-4.9 2.6-4.6 4.9.3 2 2.9 3.3 5.9 2.6 2.9-.7 4.9-2.6 4.6-4.6-.3-1.9-3-3.2-5.9-2.9zM244.8 8C106.1 8 0 113.3 0 252c0 110.9 69.8 205.8 169.5 239.2 12.8 2.3 17.3-5.6 17.3-12.1 0-6.2-.3-40.4-.3-61.4 0 0-70 15-84.7-29.8 0 0-11.4-29.1-27.8-36.6 0 0-22.9-15.7 1.6-15.4 0 0 24.9 2 38.6 25.8 21.9 38.6 58.6 27.5 72.9 20.9 2.3-16 8.8-27.1 16-33.7-55.9-6.2-112.3-14.3-112.3-110.5 0-27.5 7.6-41.3 23.6-58.9-2.6-6.5-11.1-33.3 2.6-67.9 20.9-6.5 69 27 69 27 20-5.6 41.5-8.5 62.8-8.5s42.8 2.9 62.8 8.5c0 0 48.1-33.6 69-27 13.7 34.7 5.2 61.4 2.6 67.9 16 17.7 25.8 31.5 25.8 58.9 0 96.5-58.9 104.2-114.8 110.5 9.2 7.9 17 22.9 17 46.4 0 33.7-.3 75.4-.3 83.6 0 6.5 4.6 14.4 17.3 12.1C428.2 457.8 496 362.9 496 252 496 113.3 383.5 8 244.8 8zM97.2 352.9c-1.3 1-1 3.3.7 5.2 1.6 1.6 3.9 2.3 5.2 1 1.3-1 1-3.3-.7-5.2-1.6-1.6-3.9-2.3-5.2-1zm-10.8-8.1c-.7 1.3.3 2.9 2.3 3.9 1.6 1 3.6.7 4.3-.7.7-1.3-.3-2.9-2.3-3.9-2-.6-3.6-.3-4.3.7zm32.4 35.6c-1.6 1.3-1 4.3 1.3 6.2 2.3 2.3 5.2 2.6 6.5 1 1.3-1.3.7-4.3-1.3-6.2-2.2-2.3-5.2-2.6-6.5-1zm-11.4-14.7c-1.6 1-1.6 3.6 0 5.9 1.6 2.3 4.3 3.3 5.6 2.3 1.6-1.3 1.6-3.9 0-6.2-1.4-2.3-4-3.3-5.6-2z"/></symbol>
  <symbol id="linkedin" viewBox="0 0 448 512"><path d="M416 32H31.9C14.3 32 0 46.5 0 64.3v383.4C0 465.5 14.3 480 31.9 480H416c17.6 0 32-14.5 32-32.3V64.3c0-17.8-14.4-32.3-32-32.3zM135.4 416H69V202.2h66.5V416zm-33.2-243c-21.3 0-38.5-17.3-38.5-38.5S80.9 96 102.2 96c21.2 0 38.5 17.3 38.5 38.5 0 21.3-17.2 38.5-38.5 38.5zm282.1 243h-66.4V312c0-24.8-.5-56.7-34.5-56.7-34.6 0-39.9 27-39.9 54.9V416h-66.4V202.2h63.7v29.2h.9c8.9-16.8 30.6-34.5 62.9-34.5 67.2 0 79.7 44.3 79.7 101.9V416z"/></symbol>
  <symbol id="map-marker-alt" viewBox="0 0 384 512"><path d="M215.7 499.2C267 435 384 279.4 384 192C384 86 298 0 192 0S0 86 0 192c0 87.4 117 243 168.3 307.2c12.3 15.3 35.1 15.3 47.4 0zM192 128a64 64 0 1 1 0 128 64 64 0 1 1 0-128z"/></symbol>
  <symbol id="moon" viewBox="0 0 384 512"><path d="M223.5 32C100 32 0 132.3 0 256S100 480 223.5 480c60.6 0 115.5-24.2 155.8-63.4c5-4.9 6.3-12.5 3.1-18.7s-10.1-9.7-17-8.5c-9.8 1.7-19.8 2.6-30.1 2.6c-96.9 0-175.5-78.8-175.5-176c0-65.8 36-123.1 89.3-153.3c6.1-3.5 9.2-10.5 7.7-17.3s-7.3-11.9-14.3-12.5c-6.3-.5-12.6-.8-19-.8z"/></symbol>
  <symbol id="phone" viewBox="0 0 512 512"><path d="M164.9 24.6c-7.7-18.6-28-28.5-47.4-23.2l-88 24C12.1 30.2 0 46 0 64C0 311.4 200.6 512 448 512c18 0 33.8-12.1 38.6-29.5l24-88c5.3-19.4-4.6-39.7-23.2-47.4l-96-40c-16.3-6.8-35.2-2.1-46.3 11.6L304.7 368C234.3 334.7 177.3 277.7 144 207.3L193.3 167c13.7-11.2 18.4-30 11.6-46.3l-40-96z"/></symbol>
  <symbol id="sun" viewBox="0 0 512 512"><path d="M361.5 1.2c5 2.1 8.6 6.6 9.6 11.9L391 121l107.9 19.8c5.3 1 9.8 4.6 11.9 9.6s1.5 10.7-1.6 15.2L446.9 256l62.3 90.3c3.1 4.5 3.7 10.2 1.6 15.2s-6.6 8.6-11.9 9.6L391 391 371.1 498.9c-1 5.3-4.6 9.8-9.6 11.9s-10.7 1.5-15.2-1.6L256 446.9l-90.3 62.3c-4.5 3.1-10.2 3.7-15.2 1.6s-8.6-6.6-9.6-11.9L121 391 13.1 371.1c-5.3-1-9.8-4.6-11.9-9.6s-1.5-10.7 1.6-15.2L65.1 256 2.8 165.7c-3.1-4.5-3.7-10.2-1.6-15.2s6.6-8.6 11.9-9.6L121 121 140.9 13.1c1-5.3 4.6-9.8 9.6-11.9s10.7-1.5 15.2 1.6L256 65.1 346.3 2.8c4.5-3.1 10.2-3.7 15.2-1.6zM160 256a96 96 0 1 1 192 0 96 96 0 1 1 -192 0zm224 0a128 128 0 1 0 -256 0 128 128 0 1 0 256 0z"/></symbol>
</svg>
<!-- icon-sprite:end -->

<a class="skip-link" href="#main">Skip to main content</a>

<header class="site-header" id="top">
  <div class="container site-header__inner">
    <!-- Two logo lockups, one per mode, toggled in CSS.
         A <picture media="(prefers-color-scheme: …)"> cannot be used here: that
         media query only ever reflects the OS setting, so it would ignore a
         visitor who picked the opposite mode with the header toggle. The hidden
         variant is lazy-loaded so browsers skip fetching it. -->
    <a class="site-header__brand" href="/" aria-label="Tech4TIME — home">
      <picture class="site-header__logo-wrap site-header__logo-wrap--light">
        <source
          srcset="/assets/images/logo/logo-light-180.webp 180w, /assets/images/logo/logo-light-360.webp 360w, /assets/images/logo/logo-light-540.webp 540w"
          sizes="(max-width: 48em) 140px, 180px"
          type="image/webp">
        <img
          class="site-header__logo"
          src="/assets/images/logo/logo-light-360.png"
          srcset="/assets/images/logo/logo-light-180.png 180w, /assets/images/logo/logo-light-360.png 360w, /assets/images/logo/logo-light-540.png 540w"
          sizes="(max-width: 48em) 140px, 180px"
          alt="Tech4TIME"
          width="360"
          height="128"
          fetchpriority="high"
          decoding="async">
      </picture>
      <picture class="site-header__logo-wrap site-header__logo-wrap--dark">
        <source
          srcset="/assets/images/logo/logo-dark-180.webp 180w, /assets/images/logo/logo-dark-360.webp 360w, /assets/images/logo/logo-dark-540.webp 540w"
          sizes="(max-width: 48em) 140px, 180px"
          type="image/webp">
        <img
          class="site-header__logo"
          src="/assets/images/logo/logo-dark-360.png"
          srcset="/assets/images/logo/logo-dark-180.png 180w, /assets/images/logo/logo-dark-360.png 360w, /assets/images/logo/logo-dark-540.png 540w"
          sizes="(max-width: 48em) 140px, 180px"
          alt="Tech4TIME"
          width="360"
          height="128"
          loading="lazy"
          decoding="async">
      </picture>
    </a>

    <nav class="site-nav" id="site-nav" data-nav-drawer data-open="false" aria-label="Main">
      <ul class="site-nav__list">
        <li class="site-nav__item"><a class="nav-link" href="/">Home</a></li>
        <li class="site-nav__item"><a class="nav-link" href="/pages/about/">About Us</a></li>
        <li class="site-nav__item"><a class="nav-link" href="/pages/services/">Services</a></li>
        <li class="site-nav__item"><a class="nav-link" href="/pages/company-profile/">Company Profile</a></li>
        <li class="site-nav__item"><a class="nav-link" href="/pages/careers/" aria-current="page">Careers</a></li>
        <li class="site-nav__item"><a class="nav-link" href="/pages/contact/">Contact Us</a></li>
      </ul>
    </nav>

    <div class="site-header__actions">
      <button
        class="btn btn--icon"
        type="button"
        data-theme-toggle
        aria-label="Switch to dark mode"
        aria-pressed="false">
        <svg class="icon theme-toggle__icon--moon" aria-hidden="true" focusable="false"><use href="#moon"></use></svg>
        <svg class="icon theme-toggle__icon--sun" aria-hidden="true" focusable="false"><use href="#sun"></use></svg>
      </button>

      <button
        class="nav-toggle"
        type="button"
        data-nav-toggle
        aria-controls="site-nav"
        aria-expanded="false"
        aria-label="Open navigation menu">
        <span class="nav-toggle__bar"></span>
        <span class="nav-toggle__bar"></span>
        <span class="nav-toggle__bar"></span>
      </button>
    </div>
  </div>
</header>

<main class="page__main" id="main">

  <!-- ========================== Hero banner ========================== -->
  <section class="page-hero">
    <div class="container page-hero__inner">
      <h1 class="page-hero__title">Careers</h1>
      <p class="page-hero__subtitle">Join Our Profound Team of Technology Enthusiasts</p>
    </div>
  </section>

<?php if ($jobs): ?>
  <!-- ========================= Open positions ======================== -->
  <section class="section openings" aria-labelledby="openings-heading">
    <div class="container">
      <div class="section__header">
        <span class="section__eyebrow">We are Hiring!</span>
        <h2 class="section__title" id="openings-heading">Positions Available</h2>
        <p class="section__lead">
          <?= count($jobs) === 1 ? 'One role is' : h((string)count($jobs)) . ' roles are' ?>
          open right now. Every application goes through the form on the role
          itself, so nothing gets lost in an inbox.
        </p>
      </div>

<?php foreach ($jobs as $index => $job): ?>
      <details class="job" id="<?= h((string)($job['id'] ?? '')) ?>"<?= $index === 0 ? ' open' : '' ?>>
        <summary class="job__summary">
          <h3 class="job__heading">
            <span class="job__label">
              <span class="job__title"><?= h((string)($job['title'] ?? '')) ?></span>
<?php $meta = careers_meta_line($job); if ($meta): ?>
              <span class="job__meta">
<?php foreach ($meta as $m => $value): ?>
<?php if ($m): ?>                <span class="job__meta-sep" aria-hidden="true">·</span>
<?php endif; ?>                <span class="job__meta-item"><?= h($value) ?></span>
<?php endforeach; ?>
              </span>
<?php endif; ?>
            </span>
            <svg class="icon icon--sm job__marker" aria-hidden="true" focusable="false"><use href="#chevron-right"></use></svg>
          </h3>
        </summary>

        <div class="job__panel">
<?php if (trim((string)($job['salary'] ?? '')) !== '' || trim((string)($job['closes'] ?? '')) !== ''): ?>
          <dl class="job__facts">
<?php if (trim((string)($job['salary'] ?? '')) !== ''): ?>
            <div class="job__fact">
              <dt class="job__fact-label">Salary</dt>
              <dd class="job__fact-value"><?= h((string)$job['salary']) ?></dd>
            </div>
<?php endif; ?>
<?php if (trim((string)($job['closes'] ?? '')) !== ''): ?>
            <div class="job__fact">
              <dt class="job__fact-label">Applications close</dt>
              <dd class="job__fact-value">
                <time datetime="<?= h((string)$job['closes']) ?>"><?= h(date('j F Y', (int)strtotime((string)$job['closes']))) ?></time>
              </dd>
            </div>
<?php endif; ?>
          </dl>
<?php endif; ?>

<?php foreach (CAREERS_SECTIONS as $key => $label): ?>
<?php $body = trim((string)($job[$key] ?? '')); if ($body === '') { continue; } ?>
          <div class="job__section">
            <h4 class="job__section-title"><?= h($label) ?></h4>
            <div class="job__body">
<?php /* Printed unescaped, which is safe for exactly one reason: it was put
         through careers_sanitise_html() before it was stored, and that
         function writes its output from an allow-list rather than passing
         anything through. Nothing else may ever be echoed raw here. */ ?>
              <?= $body ?>
            </div>
          </div>
<?php endforeach; ?>

<?php if (trim((string)($job['apply_url'] ?? '')) !== ''): ?>
          <div class="job__apply">
            <a class="btn btn--primary btn--lg" href="<?= h((string)$job['apply_url']) ?>"
               target="_blank" rel="noopener noreferrer">
              Apply for this role
              <svg class="icon icon--sm" aria-hidden="true" focusable="false"><use href="#arrow-right"></use></svg>
            </a>
            <p class="job__apply-note">Opens the application form in a new tab.</p>
          </div>
<?php endif; ?>
        </div>
      </details>
<?php endforeach; ?>
    </div>
  </section>

  <!-- ========================== Share your CV ======================== -->
  <section class="section section--surface cv" aria-labelledby="cv-heading">
    <div class="container">
      <div class="section__header">
        <h2 class="section__title" id="cv-heading">Share Your CV With Us</h2>
        <p class="section__lead">
          None of the roles above a fit? We are often up for a hunt. Send us
          your CV anyway, and if a suitable opportunity comes up we will reach
          out to you.
        </p>
      </div>
<?php if ($cvForm !== ''): ?>
      <div class="cv__actions">
        <a class="btn btn--secondary btn--lg" href="<?= h($cvForm) ?>"
           target="_blank" rel="noopener noreferrer">
          Ready to take the chance?
          <svg class="icon icon--sm" aria-hidden="true" focusable="false"><use href="#file-upload"></use></svg>
        </a>
      </div>
<?php endif; ?>
    </div>
  </section>

<?php else: ?>
  <!-- ===================== No openings right now ===================== -->
  <section class="section openings openings--empty" aria-labelledby="openings-heading">
    <div class="container">
      <div class="section__header">
        <span class="section__eyebrow">Nothing open today</span>
        <h2 class="section__title" id="openings-heading">Stay Tuned for Opportunities</h2>
        <p class="section__lead">
          We have no positions posted at the moment. That changes often — and
          we are often up for a hunt. Send us your CV now, and if a suitable
          opportunity comes up we will reach out to you.
        </p>
      </div>

      <div class="empty-state">
        <span class="empty-state__icon">
          <svg class="icon" aria-hidden="true" focusable="false"><use href="#briefcase"></use></svg>
        </span>
        <h3 class="empty-state__title">Share Your CV With Us</h3>
        <p class="empty-state__text">
          We keep every CV on file against the roles we expect to open next, so
          getting yours in before a posting goes up is worth doing.
        </p>
<?php if ($cvForm !== ''): ?>
        <a class="btn btn--primary btn--lg" href="<?= h($cvForm) ?>"
           target="_blank" rel="noopener noreferrer">
          Ready to take the chance?
          <svg class="icon icon--sm" aria-hidden="true" focusable="false"><use href="#file-upload"></use></svg>
        </a>
<?php endif; ?>
      </div>
    </div>
  </section>
<?php endif; ?>

  <!-- ============================== CTA ============================== -->
  <section class="cta-band cta-band--base">
    <div class="container cta-band__inner">
      <h2 class="cta-band__title">Questions before you apply?</h2>
      <p class="cta-band__text">
        Ask us anything about a role, the team or how we work.
      </p>
      <div class="cta-band__actions">
        <a class="btn btn--primary btn--lg" href="/pages/contact/">Contact Us</a>
        <a class="btn btn--ghost btn--lg" href="/pages/company-profile/">About the Company</a>
      </div>
    </div>
  </section>

</main>

<footer class="site-footer">
  <div class="container">
    <div class="site-footer__main">
      <!-- Brand -->
      <div class="site-footer__brand">
        <a href="/" aria-label="Tech4TIME — home">
          <picture class="site-footer__logo-wrap site-footer__logo-wrap--light">
            <source srcset="/assets/images/logo/logo-light-360.webp" type="image/webp">
            <img class="site-footer__logo" src="/assets/images/logo/logo-light-360.png"
                 alt="Tech4TIME" width="360" height="128" loading="lazy" decoding="async">
          </picture>
          <picture class="site-footer__logo-wrap site-footer__logo-wrap--dark">
            <source srcset="/assets/images/logo/logo-dark-360.webp" type="image/webp">
            <img class="site-footer__logo" src="/assets/images/logo/logo-dark-360.png"
                 alt="Tech4TIME" width="360" height="128" loading="lazy" decoding="async">
          </picture>
        </a>
        <p class="site-footer__tagline">Orchestrating Technology with Time</p>
        <p class="site-footer__description">
          Open-Source &amp; Enterprise-grade cybersecurity, software development, and IT
          solutions. Orchestrate, build, maintain and protect your business with our
          profound solutions.
        </p>
        <ul class="site-footer__social">
          <li>
            <a class="btn btn--icon" href="https://www.linkedin.com/company/tech4time-bd/"
               target="_blank" rel="noopener noreferrer" aria-label="Tech4TIME on LinkedIn">
              <svg class="icon" aria-hidden="true" focusable="false"><use href="#linkedin"></use></svg>
            </a>
          </li>
          <li>
            <a class="btn btn--icon" href="https://github.com/M-s-Tech4TIME"
               target="_blank" rel="noopener noreferrer" aria-label="Tech4TIME on GitHub">
              <svg class="icon" aria-hidden="true" focusable="false"><use href="#github"></use></svg>
            </a>
          </li>
        </ul>
      </div>

      <!-- Quick links -->
      <nav class="site-footer__section" aria-labelledby="footer-links-heading">
        <h2 class="site-footer__heading" id="footer-links-heading">Quick Links</h2>
        <ul class="site-footer__list">
          <li><a class="site-footer__link" href="/">Home</a></li>
          <li><a class="site-footer__link" href="/pages/about/">About Us</a></li>
          <li><a class="site-footer__link" href="/pages/company-profile/">Company Profile</a></li>
          <li><a class="site-footer__link" href="/pages/careers/">Careers</a></li>
          <li><a class="site-footer__link" href="/pages/resource-certifications/">Resource Certifications</a></li>
          <li><a class="site-footer__link" href="/pages/branding-and-advertisement/">Branding &amp; Advertisement</a></li>
          <li><a class="site-footer__link" href="/pages/contact/">Contact Us</a></li>
        </ul>
      </nav>

      <!-- Services -->
      <nav class="site-footer__section" aria-labelledby="footer-services-heading">
        <h2 class="site-footer__heading" id="footer-services-heading">Our Services</h2>
        <ul class="site-footer__list">
          <li><a class="site-footer__link" href="/pages/services/">All Services</a></li>
          <li><a class="site-footer__link" href="/pages/services/cybersecurity/">Cybersecurity</a></li>
          <li><a class="site-footer__link" href="/pages/services/software-development/">Software Development</a></li>
          <li><a class="site-footer__link" href="/pages/services/cloud-infrastructure/">Cloud Infrastructure</a></li>
          <li><a class="site-footer__link" href="/pages/services/hr-solutions/">Human Resource Provision</a></li>
          <li><a class="site-footer__link" href="/pages/services/it-equipment-supply/">IT Equipment Supply</a></li>
          <li><a class="site-footer__link" href="/pages/services/it-consultancy-training/">IT Consultancy &amp; Training</a></li>
        </ul>
      </nav>

      <!-- Contact -->
      <div class="site-footer__section">
        <h2 class="site-footer__heading">Contact Info</h2>
        <address class="site-footer__contact">
          <div class="contact-item">
            <svg class="icon contact-item__icon" aria-hidden="true" focusable="false"><use href="#phone"></use></svg>
            <div>
              <span class="contact-item__label">Bangladesh</span>
              <a href="tel:+8801320571562">+880 1320571562</a><br>
              <a href="tel:+8801881873463">+880 1881873463</a><br>
              <a href="tel:+8801847313835">+880 1847313835</a>
              <span class="contact-item__note">Sunday – Thursday</span>

              <span class="contact-item__label">Malaysia</span>
              <a href="tel:+60198527096">+60 198527096</a>
              <span class="contact-item__note">Monday – Friday</span>
            </div>
          </div>

          <div class="contact-item">
            <svg class="icon contact-item__icon" aria-hidden="true" focusable="false"><use href="#envelope"></use></svg>
            <a href="mailto:info@tech4time.bd">info@tech4time.bd</a>
          </div>

          <div class="contact-item">
            <svg class="icon contact-item__icon" aria-hidden="true" focusable="false"><use href="#map-marker-alt"></use></svg>
            <div>
              <span class="contact-item__label">Dhaka, Bangladesh</span>
              278/3, Manikdi, Dhaka - 1206<br>
              <span class="contact-item__label">Selangor, Malaysia</span>
              68100 Batu Caves<br>
              <span class="contact-item__label">Brussels, Belgium</span>
              367, Avenue Louise
            </div>
          </div>

          <div class="contact-item">
            <svg class="icon contact-item__icon" aria-hidden="true" focusable="false"><use href="#clock"></use></svg>
            <div>
              <span class="contact-item__label">Bangladesh Office</span>
              Sun – Thu: 9:00 AM – 6:00 PM
              <span class="contact-item__label">Malaysia Office</span>
              Mon – Fri: 9:00 AM – 6:00 PM
            </div>
          </div>
        </address>
      </div>
    </div>

    <div class="site-footer__bottom">
      <div>
        <p class="site-footer__copyright">
          &copy; <span data-current-year>2026</span> Tech4TIME. All rights reserved.
        </p>
        <ul class="site-footer__legal">
          <li><a class="site-footer__link" href="/pages/privacy-policy/">Privacy Policy</a></li>
        </ul>
      </div>

      <button class="btn btn--icon" type="button" data-back-to-top aria-label="Back to top">
        <svg class="icon" aria-hidden="true" focusable="false"><use href="#arrow-up"></use></svg>
      </button>
    </div>
  </div>
</footer>

<!-- Deferred so nothing blocks rendering. Order matters only in that main.js
     runs last: each module registers itself on window.Tech4Time, and main.js
     calls their init(). Pages that need no forms can omit forms.js. -->
<script src="/assets/js/theme-toggle.js" defer></script>
<script src="/assets/js/nav.js" defer></script>
<script src="/assets/js/animations.js" defer></script>
<script src="/assets/js/forms.js" defer></script>
<script src="/assets/js/dashboard.js" defer></script>
<script src="/assets/js/tech-sphere.js" defer></script>
<script src="/assets/js/main.js" defer></script>
</body>
</html>
