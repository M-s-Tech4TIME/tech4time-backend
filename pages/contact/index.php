<?php
/**
 * Tech4TIME — contact page.
 *
 * PHP rather than a static file for the same reason the careers page is: what
 * it says changes without a redeploy. Addresses, phone numbers, opening hours
 * and the copy around them live in content/contact.json and are edited through
 * /admin/; this renders them.
 *
 * Rendered on the SERVER, not fetched in the browser. A contact page whose
 * addresses arrive by JavaScript is one a search engine indexes unreliably,
 * and it is the page a search engine is most often asked for by name.
 *
 * If the data file is missing or unreadable, contact_load() falls back field by
 * field to the details the site was deployed with — stale at worst, never
 * blank.
 */

declare(strict_types=1);

require __DIR__ . '/../../lib/contact.php';

$data    = contact_load();
$offices = contact_shown_offices($data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?= h($data['meta']['title']) ?></title>
<meta name="description" content="<?= h($data['meta']['description']) ?>">
<link rel="canonical" href="https://tech4time.bd/pages/contact/">

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
<meta property="og:title" content="<?= h($data['meta']['share_title']) ?>">
<meta property="og:description" content="<?= h($data['meta']['description']) ?>">
<meta property="og:url" content="https://tech4time.bd/pages/contact/">
<meta property="og:image" content="https://tech4time.bd/assets/images/og/tech4time-og.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Tech4TIME — Orchestrating Technology with Time">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($data['meta']['share_title']) ?>">
<meta name="twitter:description" content="<?= h($data['meta']['description']) ?>">
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
<link rel="stylesheet" href="/assets/css/pages/contact.css">

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
          "addressRegion": "Selangor",
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
          "availableLanguage": [
            "English",
            "Bengali"
          ]
        },
        {
          "@type": "ContactPoint",
          "telephone": "+8801881873463",
          "email": "info@tech4time.bd",
          "contactType": "customer service",
          "areaServed": "BD",
          "availableLanguage": [
            "English",
            "Bengali"
          ]
        },
        {
          "@type": "ContactPoint",
          "telephone": "+8801847313835",
          "email": "info@tech4time.bd",
          "contactType": "customer service",
          "areaServed": "BD",
          "availableLanguage": [
            "English",
            "Bengali"
          ]
        },
        {
          "@type": "ContactPoint",
          "telephone": "+60198527096",
          "email": "info@tech4time.bd",
          "contactType": "customer service",
          "areaServed": "MY",
          "availableLanguage": [
            "English"
          ]
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
      "name": "Contact Us",
      "item": "https://tech4time.bd/pages/contact/"
    }
  ]
}
</script>

<?php /* The ContactPage graph, built from the same records the page below
         renders. Generated rather than written out so it cannot drift from
         what a visitor is being shown — a wrong address in structured data is
         wrong in Google's knowledge panel, where nobody on this end sees it. */ ?>
<script type="application/ld+json">
<?= json_encode(contact_page_schema($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>
</head>

<body class="page">
<!-- icon-sprite:start -->
<svg class="icon-sprite" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
  <symbol id="arrow-up" viewBox="0 0 384 512"><path d="M214.6 41.4c-12.5-12.5-32.8-12.5-45.3 0l-160 160c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L160 141.2V448c0 17.7 14.3 32 32 32s32-14.3 32-32V141.2L329.4 246.6c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3l-160-160z"/></symbol>
  <symbol id="building" viewBox="0 0 384 512"><path d="M48 0C21.5 0 0 21.5 0 48V464c0 26.5 21.5 48 48 48h96V432c0-26.5 21.5-48 48-48s48 21.5 48 48v80h96c26.5 0 48-21.5 48-48V48c0-26.5-21.5-48-48-48H48zM64 240c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H80c-8.8 0-16-7.2-16-16V240zm112-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H176c-8.8 0-16-7.2-16-16V240c0-8.8 7.2-16 16-16zm80 16c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H272c-8.8 0-16-7.2-16-16V240zM80 96h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H80c-8.8 0-16-7.2-16-16V112c0-8.8 7.2-16 16-16zm80 16c0-8.8 7.2-16 16-16h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H176c-8.8 0-16-7.2-16-16V112zM272 96h32c8.8 0 16 7.2 16 16v32c0 8.8-7.2 16-16 16H272c-8.8 0-16-7.2-16-16V112c0-8.8 7.2-16 16-16z"/></symbol>
  <symbol id="calendar-alt" viewBox="0 0 448 512"><path d="M128 0c17.7 0 32 14.3 32 32V64H288V32c0-17.7 14.3-32 32-32s32 14.3 32 32V64h48c26.5 0 48 21.5 48 48v48H0V112C0 85.5 21.5 64 48 64H96V32c0-17.7 14.3-32 32-32zM0 192H448V464c0 26.5-21.5 48-48 48H48c-26.5 0-48-21.5-48-48V192zm64 80v32c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16V272c0-8.8-7.2-16-16-16H80c-8.8 0-16 7.2-16 16zm128 0v32c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16V272c0-8.8-7.2-16-16-16H208c-8.8 0-16 7.2-16 16zm144-16c-8.8 0-16 7.2-16 16v32c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16V272c0-8.8-7.2-16-16-16H336zM64 400v32c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16V400c0-8.8-7.2-16-16-16H80c-8.8 0-16 7.2-16 16zm144-16c-8.8 0-16 7.2-16 16v32c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16V400c0-8.8-7.2-16-16-16H208zm112 16v32c0 8.8 7.2 16 16 16h32c8.8 0 16-7.2 16-16V400c0-8.8-7.2-16-16-16H336c-8.8 0-16 7.2-16 16z"/></symbol>
  <symbol id="clock" viewBox="0 0 512 512"><path d="M256 0a256 256 0 1 1 0 512A256 256 0 1 1 256 0zM232 120V256c0 8 4 15.5 10.7 20l96 64c11 7.4 25.9 4.4 33.3-6.7s4.4-25.9-6.7-33.3L280 243.2V120c0-13.3-10.7-24-24-24s-24 10.7-24 24z"/></symbol>
  <symbol id="cogs" viewBox="0 0 640 512"><path d="M308.5 135.3c7.1-6.3 9.9-16.2 6.2-25c-2.3-5.3-4.8-10.5-7.6-15.5L304 89.4c-3-5-6.3-9.9-9.8-14.6c-5.7-7.6-15.7-10.1-24.7-7.1l-28.2 9.3c-10.7-8.8-23-16-36.2-20.9L199 27.1c-1.9-9.3-9.1-16.7-18.5-17.8C173.9 8.4 167.2 8 160.4 8h-.7c-6.8 0-13.5 .4-20.1 1.2c-9.4 1.1-16.6 8.6-18.5 17.8L115 56.1c-13.3 5-25.5 12.1-36.2 20.9L50.5 67.8c-9-3-19-.5-24.7 7.1c-3.5 4.7-6.8 9.6-9.9 14.6l-3 5.3c-2.8 5-5.3 10.2-7.6 15.6c-3.7 8.7-.9 18.6 6.2 25l22.2 19.8C32.6 161.9 32 168.9 32 176s.6 14.1 1.7 20.9L11.5 216.7c-7.1 6.3-9.9 16.2-6.2 25c2.3 5.3 4.8 10.5 7.6 15.6l3 5.2c3 5.1 6.3 9.9 9.9 14.6c5.7 7.6 15.7 10.1 24.7 7.1l28.2-9.3c10.7 8.8 23 16 36.2 20.9l6.1 29.1c1.9 9.3 9.1 16.7 18.5 17.8c6.7 .8 13.5 1.2 20.4 1.2s13.7-.4 20.4-1.2c9.4-1.1 16.6-8.6 18.5-17.8l6.1-29.1c13.3-5 25.5-12.1 36.2-20.9l28.2 9.3c9 3 19 .5 24.7-7.1c3.5-4.7 6.8-9.5 9.8-14.6l3.1-5.4c2.8-5 5.3-10.2 7.6-15.5c3.7-8.7 .9-18.6-6.2-25l-22.2-19.8c1.1-6.8 1.7-13.8 1.7-20.9s-.6-14.1-1.7-20.9l22.2-19.8zM112 176a48 48 0 1 1 96 0 48 48 0 1 1 -96 0zM504.7 500.5c6.3 7.1 16.2 9.9 25 6.2c5.3-2.3 10.5-4.8 15.5-7.6l5.4-3.1c5-3 9.9-6.3 14.6-9.8c7.6-5.7 10.1-15.7 7.1-24.7l-9.3-28.2c8.8-10.7 16-23 20.9-36.2l29.1-6.1c9.3-1.9 16.7-9.1 17.8-18.5c.8-6.7 1.2-13.5 1.2-20.4s-.4-13.7-1.2-20.4c-1.1-9.4-8.6-16.6-17.8-18.5L583.9 307c-5-13.3-12.1-25.5-20.9-36.2l9.3-28.2c3-9 .5-19-7.1-24.7c-4.7-3.5-9.6-6.8-14.6-9.9l-5.3-3c-5-2.8-10.2-5.3-15.6-7.6c-8.7-3.7-18.6-.9-25 6.2l-19.8 22.2c-6.8-1.1-13.8-1.7-20.9-1.7s-14.1 .6-20.9 1.7l-19.8-22.2c-6.3-7.1-16.2-9.9-25-6.2c-5.3 2.3-10.5 4.8-15.6 7.6l-5.2 3c-5.1 3-9.9 6.3-14.6 9.9c-7.6 5.7-10.1 15.7-7.1 24.7l9.3 28.2c-8.8 10.7-16 23-20.9 36.2L315.1 313c-9.3 1.9-16.7 9.1-17.8 18.5c-.8 6.7-1.2 13.5-1.2 20.4s.4 13.7 1.2 20.4c1.1 9.4 8.6 16.6 17.8 18.5l29.1 6.1c5 13.3 12.1 25.5 20.9 36.2l-9.3 28.2c-3 9-.5 19 7.1 24.7c4.7 3.5 9.5 6.8 14.6 9.8l5.4 3.1c5 2.8 10.2 5.3 15.5 7.6c8.7 3.7 18.6 .9 25-6.2l19.8-22.2c6.8 1.1 13.8 1.7 20.9 1.7s14.1-.6 20.9-1.7l19.8 22.2zM464 304a48 48 0 1 1 0 96 48 48 0 1 1 0-96z"/></symbol>
  <symbol id="comment-alt" viewBox="0 0 512 512"><path d="M64 0C28.7 0 0 28.7 0 64V352c0 35.3 28.7 64 64 64h96v80c0 6.1 3.4 11.6 8.8 14.3s11.9 2.1 16.8-1.5L309.3 416H448c35.3 0 64-28.7 64-64V64c0-35.3-28.7-64-64-64H64z"/></symbol>
  <symbol id="envelope" viewBox="0 0 512 512"><path d="M48 64C21.5 64 0 85.5 0 112c0 15.1 7.1 29.3 19.2 38.4L236.8 313.6c11.4 8.5 27 8.5 38.4 0L492.8 150.4c12.1-9.1 19.2-23.3 19.2-38.4c0-26.5-21.5-48-48-48H48zM0 176V384c0 35.3 28.7 64 64 64H448c35.3 0 64-28.7 64-64V176L294.4 339.2c-22.8 17.1-54 17.1-76.8 0L0 176z"/></symbol>
  <symbol id="github" viewBox="0 0 496 512"><path d="M165.9 397.4c0 2-2.3 3.6-5.2 3.6-3.3.3-5.6-1.3-5.6-3.6 0-2 2.3-3.6 5.2-3.6 3-.3 5.6 1.3 5.6 3.6zm-31.1-4.5c-.7 2 1.3 4.3 4.3 4.9 2.6 1 5.6 0 6.2-2s-1.3-4.3-4.3-5.2c-2.6-.7-5.5.3-6.2 2.3zm44.2-1.7c-2.9.7-4.9 2.6-4.6 4.9.3 2 2.9 3.3 5.9 2.6 2.9-.7 4.9-2.6 4.6-4.6-.3-1.9-3-3.2-5.9-2.9zM244.8 8C106.1 8 0 113.3 0 252c0 110.9 69.8 205.8 169.5 239.2 12.8 2.3 17.3-5.6 17.3-12.1 0-6.2-.3-40.4-.3-61.4 0 0-70 15-84.7-29.8 0 0-11.4-29.1-27.8-36.6 0 0-22.9-15.7 1.6-15.4 0 0 24.9 2 38.6 25.8 21.9 38.6 58.6 27.5 72.9 20.9 2.3-16 8.8-27.1 16-33.7-55.9-6.2-112.3-14.3-112.3-110.5 0-27.5 7.6-41.3 23.6-58.9-2.6-6.5-11.1-33.3 2.6-67.9 20.9-6.5 69 27 69 27 20-5.6 41.5-8.5 62.8-8.5s42.8 2.9 62.8 8.5c0 0 48.1-33.6 69-27 13.7 34.7 5.2 61.4 2.6 67.9 16 17.7 25.8 31.5 25.8 58.9 0 96.5-58.9 104.2-114.8 110.5 9.2 7.9 17 22.9 17 46.4 0 33.7-.3 75.4-.3 83.6 0 6.5 4.6 14.4 17.3 12.1C428.2 457.8 496 362.9 496 252 496 113.3 383.5 8 244.8 8zM97.2 352.9c-1.3 1-1 3.3.7 5.2 1.6 1.6 3.9 2.3 5.2 1 1.3-1 1-3.3-.7-5.2-1.6-1.6-3.9-2.3-5.2-1zm-10.8-8.1c-.7 1.3.3 2.9 2.3 3.9 1.6 1 3.6.7 4.3-.7.7-1.3-.3-2.9-2.3-3.9-2-.6-3.6-.3-4.3.7zm32.4 35.6c-1.6 1.3-1 4.3 1.3 6.2 2.3 2.3 5.2 2.6 6.5 1 1.3-1.3.7-4.3-1.3-6.2-2.2-2.3-5.2-2.6-6.5-1zm-11.4-14.7c-1.6 1-1.6 3.6 0 5.9 1.6 2.3 4.3 3.3 5.6 2.3 1.6-1.3 1.6-3.9 0-6.2-1.4-2.3-4-3.3-5.6-2z"/></symbol>
  <symbol id="globe" viewBox="0 0 512 512"><path d="M352 256c0 22.2-1.2 43.6-3.3 64H163.3c-2.2-20.4-3.3-41.8-3.3-64s1.2-43.6 3.3-64H348.7c2.2 20.4 3.3 41.8 3.3 64zm28.8-64H503.9c5.3 20.5 8.1 41.9 8.1 64s-2.8 43.5-8.1 64H380.8c2.1-20.6 3.2-42 3.2-64s-1.1-43.4-3.2-64zm112.6-32H376.7c-10-63.9-29.8-117.4-55.3-151.6c78.3 20.7 142 77.5 171.9 151.6zm-149.1 0H167.7c6.1-36.4 15.5-68.6 27-94.7c10.5-23.6 22.2-40.7 33.5-51.5C239.4 3.2 248.7 0 256 0s16.6 3.2 27.8 13.8c11.3 10.8 23 27.9 33.5 51.5c11.6 26 20.9 58.2 27 94.7zm-209 0H18.6C48.6 85.9 112.2 29.1 190.6 8.4C165.1 42.6 145.3 96.1 135.3 160zM8.1 192H131.2c-2.1 20.6-3.2 42-3.2 64s1.1 43.4 3.2 64H8.1C2.8 299.5 0 278.1 0 256s2.8-43.5 8.1-64zM194.7 446.6c-11.6-26-20.9-58.2-27-94.6H344.3c-6.1 36.4-15.5 68.6-27 94.6c-10.5 23.6-22.2 40.7-33.5 51.5C272.6 508.8 263.3 512 256 512s-16.6-3.2-27.8-13.8c-11.3-10.8-23-27.9-33.5-51.5zM135.3 352c10 63.9 29.8 117.4 55.3 151.6C112.2 482.9 48.6 426.1 18.6 352H135.3zm358.1 0c-30 74.1-93.6 130.9-171.9 151.6c25.5-34.2 45.2-87.7 55.3-151.6H493.4z"/></symbol>
  <symbol id="grid-dots" viewBox="0 0 24 24"><circle cx="5" cy="5" r="2.1"/><circle cx="12" cy="5" r="2.1"/><circle cx="19" cy="5" r="2.1"/><circle cx="5" cy="12" r="2.1"/><circle cx="12" cy="12" r="2.1"/><circle cx="19" cy="12" r="2.1"/><circle cx="5" cy="19" r="2.1"/><circle cx="12" cy="19" r="2.1"/><circle cx="19" cy="19" r="2.1"/></symbol>
  <symbol id="headset" viewBox="0 0 512 512"><path d="M256 48C141.1 48 48 141.1 48 256v40c0 13.3-10.7 24-24 24s-24-10.7-24-24V256C0 114.6 114.6 0 256 0S512 114.6 512 256V400.1c0 48.6-39.4 88-88.1 88L313.6 488c-8.3 14.3-23.8 24-41.6 24H240c-26.5 0-48-21.5-48-48s21.5-48 48-48h32c17.8 0 33.3 9.7 41.6 24l110.4 .1c22.1 0 40-17.9 40-40V256c0-114.9-93.1-208-208-208zM144 208h16c17.7 0 32 14.3 32 32V352c0 17.7-14.3 32-32 32H144c-35.3 0-64-28.7-64-64V272c0-35.3 28.7-64 64-64zm224 0c35.3 0 64 28.7 64 64v48c0 35.3-28.7 64-64 64H352c-17.7 0-32-14.3-32-32V240c0-17.7 14.3-32 32-32h16z"/></symbol>
  <symbol id="home" viewBox="0 0 576 512"><path d="M575.8 255.5c0 18-15 32.1-32 32.1h-32l.7 160.2c0 2.7-.2 5.4-.5 8.1V472c0 22.1-17.9 40-40 40H456c-1.1 0-2.2 0-3.3-.1c-1.4 .1-2.8 .1-4.2 .1H416 392c-22.1 0-40-17.9-40-40V448 384c0-17.7-14.3-32-32-32H256c-17.7 0-32 14.3-32 32v64 24c0 22.1-17.9 40-40 40H160 128.1c-1.5 0-3-.1-4.5-.2c-1.2 .1-2.4 .2-3.6 .2H104c-22.1 0-40-17.9-40-40V360c0-.9 0-1.9 .1-2.8V287.6H32c-18 0-32-14-32-32.1c0-9 3-17 10-24L266.4 8c7-7 15-8 22-8s15 2 21 7L564.8 231.5c8 7 12 15 11 24z"/></symbol>
  <symbol id="info-circle" viewBox="0 0 512 512"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM216 336h24V272H216c-13.3 0-24-10.7-24-24s10.7-24 24-24h48c13.3 0 24 10.7 24 24v88h8c13.3 0 24 10.7 24 24s-10.7 24-24 24H216c-13.3 0-24-10.7-24-24s10.7-24 24-24zm40-208a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></symbol>
  <symbol id="linkedin" viewBox="0 0 448 512"><path d="M416 32H31.9C14.3 32 0 46.5 0 64.3v383.4C0 465.5 14.3 480 31.9 480H416c17.6 0 32-14.5 32-32.3V64.3c0-17.8-14.4-32.3-32-32.3zM135.4 416H69V202.2h66.5V416zm-33.2-243c-21.3 0-38.5-17.3-38.5-38.5S80.9 96 102.2 96c21.2 0 38.5 17.3 38.5 38.5 0 21.3-17.2 38.5-38.5 38.5zm282.1 243h-66.4V312c0-24.8-.5-56.7-34.5-56.7-34.6 0-39.9 27-39.9 54.9V416h-66.4V202.2h63.7v29.2h.9c8.9-16.8 30.6-34.5 62.9-34.5 67.2 0 79.7 44.3 79.7 101.9V416z"/></symbol>
  <symbol id="lock" viewBox="0 0 448 512"><path d="M144 144v48H304V144c0-44.2-35.8-80-80-80s-80 35.8-80 80zM80 192V144C80 64.5 144.5 0 224 0s144 64.5 144 144v48h16c35.3 0 64 28.7 64 64V448c0 35.3-28.7 64-64 64H64c-35.3 0-64-28.7-64-64V256c0-35.3 28.7-64 64-64H80z"/></symbol>
  <symbol id="map-marker-alt" viewBox="0 0 384 512"><path d="M215.7 499.2C267 435 384 279.4 384 192C384 86 298 0 192 0S0 86 0 192c0 87.4 117 243 168.3 307.2c12.3 15.3 35.1 15.3 47.4 0zM192 128a64 64 0 1 1 0 128 64 64 0 1 1 0-128z"/></symbol>
  <symbol id="mobile-alt" viewBox="0 0 384 512"><path d="M16 64C16 28.7 44.7 0 80 0H304c35.3 0 64 28.7 64 64V448c0 35.3-28.7 64-64 64H80c-35.3 0-64-28.7-64-64V64zM224 448a32 32 0 1 0 -64 0 32 32 0 1 0 64 0zM304 64H80V384H304V64z"/></symbol>
  <symbol id="moon" viewBox="0 0 384 512"><path d="M223.5 32C100 32 0 132.3 0 256S100 480 223.5 480c60.6 0 115.5-24.2 155.8-63.4c5-4.9 6.3-12.5 3.1-18.7s-10.1-9.7-17-8.5c-9.8 1.7-19.8 2.6-30.1 2.6c-96.9 0-175.5-78.8-175.5-176c0-65.8 36-123.1 89.3-153.3c6.1-3.5 9.2-10.5 7.7-17.3s-7.3-11.9-14.3-12.5c-6.3-.5-12.6-.8-19-.8z"/></symbol>
  <symbol id="paper-plane" viewBox="0 0 512 512"><path d="M498.1 5.6c10.1 7 15.4 19.1 13.5 31.2l-64 416c-1.5 9.7-7.4 18.2-16 23s-18.9 5.4-28 1.6L284 427.7l-68.5 74.1c-8.9 9.7-22.9 12.9-35.2 8.1S160 493.2 160 480V396.4c0-4 1.5-7.8 4.2-10.7L331.8 202.8c5.8-6.3 5.6-16-.4-22s-15.7-6.4-22-.7L106 360.8 17.7 316.6C7.1 311.3 .3 300.7 0 288.9s5.9-22.8 16.1-28.7l448-256c10.7-6.1 23.9-5.5 34 1.4z"/></symbol>
  <symbol id="phone" viewBox="0 0 512 512"><path d="M164.9 24.6c-7.7-18.6-28-28.5-47.4-23.2l-88 24C12.1 30.2 0 46 0 64C0 311.4 200.6 512 448 512c18 0 33.8-12.1 38.6-29.5l24-88c5.3-19.4-4.6-39.7-23.2-47.4l-96-40c-16.3-6.8-35.2-2.1-46.3 11.6L304.7 368C234.3 334.7 177.3 277.7 144 207.3L193.3 167c13.7-11.2 18.4-30 11.6-46.3l-40-96z"/></symbol>
  <symbol id="sun" viewBox="0 0 512 512"><path d="M361.5 1.2c5 2.1 8.6 6.6 9.6 11.9L391 121l107.9 19.8c5.3 1 9.8 4.6 11.9 9.6s1.5 10.7-1.6 15.2L446.9 256l62.3 90.3c3.1 4.5 3.7 10.2 1.6 15.2s-6.6 8.6-11.9 9.6L391 391 371.1 498.9c-1 5.3-4.6 9.8-9.6 11.9s-10.7 1.5-15.2-1.6L256 446.9l-90.3 62.3c-4.5 3.1-10.2 3.7-15.2 1.6s-8.6-6.6-9.6-11.9L121 391 13.1 371.1c-5.3-1-9.8-4.6-11.9-9.6s-1.5-10.7 1.6-15.2L65.1 256 2.8 165.7c-3.1-4.5-3.7-10.2-1.6-15.2s6.6-8.6 11.9-9.6L121 121 140.9 13.1c1-5.3 4.6-9.8 9.6-11.9s10.7-1.5 15.2 1.6L256 65.1 346.3 2.8c4.5-3.1 10.2-3.7 15.2-1.6zM160 256a96 96 0 1 1 192 0 96 96 0 1 1 -192 0zm224 0a128 128 0 1 0 -256 0 128 128 0 1 0 256 0z"/></symbol>
  <symbol id="times" viewBox="0 0 384 512"><path d="M342.6 150.6c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L192 210.7 86.6 105.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3L146.7 256 41.4 361.4c-12.5 12.5-12.5 32.8 0 45.3s32.8 12.5 45.3 0L192 301.3 297.4 406.6c12.5 12.5 32.8 12.5 45.3 0s12.5-32.8 0-45.3L237.3 256 342.6 150.6z"/></symbol>
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

    <!-- No data-nav-drawer here. This nav is the desktop navigation and
         nothing opens or closes it; below 64em it is hidden and the dock
         panel is the thing that opens. nav.js binds to the first
         [data-nav-drawer] in the document, so leaving the attribute on this
         element pointed the menu button at a display:none nav — it dutifully
         set data-open="true" on something nobody could see. -->
    <nav class="site-nav" id="site-nav" aria-label="Main">
      <ul class="site-nav__list">
        <li class="site-nav__item"><a class="nav-link" href="/">Home</a></li>
        <li class="site-nav__item"><a class="nav-link" href="/pages/about/">About Us</a></li>
        <li class="site-nav__item"><a class="nav-link" href="/pages/services/">Services</a></li>
        <li class="site-nav__item"><a class="nav-link" href="/pages/company-profile/">Company Profile</a></li>
        <li class="site-nav__item"><a class="nav-link" href="/pages/careers/">Careers</a></li>
        <li class="site-nav__item"><a class="nav-link" href="/pages/contact/" aria-current="page">Contact Us</a></li>
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
    </div>
  </div>
</header>

<main class="page__main" id="main">

  <!-- ========================== Hero banner ========================== -->
  <section class="page-hero">
    <!--hero-circuit:start-->
    <!-- Circuitry framing the page title, on all four edges. Drawn rather than
         photographed: about a kilobyte, it tints itself from the theme tokens,
         and it costs the Largest Contentful Paint nothing because there is no
         image to fetch.

         aria-hidden, and inside the hero but behind it: this is texture around
         the title, and it says nothing.

         The geometry is declared once, in the top edge's <defs>. SVG ids are
         document-scoped, so the other three edges reference the same paths and
         are flipped in CSS — two sets of geometry, four sides.

         preserveAspectRatio="none" because these strips run the width of the
         viewport at a fixed height, and any aspect-preserving fit either crops
         most of the drawing away or letterboxes it. The traces are built from
         horizontal runs and vertical drops for that reason: stretching one
         axis lengthens the runs and moves the drops apart, which is what a
         longer stretch of circuit board would look like anyway.

         Every charge runs on its own prime number of seconds, as the dock's
         does, so the four edges never settle back into an arrangement anyone
         has already seen. -->
    <div class="hero-circuit" aria-hidden="true">
      <svg class="hero-circuit__edge hero-circuit__edge--top" viewBox="0 0 1440 72"
           preserveAspectRatio="none" focusable="false">
        <defs>
          <path id="hc-h-a" d="M0 18h206v26h178v-26h242v34h198"/>
          <path id="hc-h-b" d="M1440 46h-286v-22H902v30H628v-30H318"/>
          <path id="hc-h-c" d="M0 58h122v-30h150v22h268v-40h214"/>
          <path id="hc-h-d" d="M1440 12h-192v28h-206v20H714v-14H452"/>
          <path id="hc-v-a" d="M18 0v182h30v148H20v170"/>
          <path id="hc-v-b" d="M54 480V330h-26V190h30V44"/>
          <path id="hc-v-c" d="M36 0v96h26v128H30v186"/>
          <path id="hc-v-d" d="M62 480V366H36V246h26V120"/>
        </defs>

        <g class="hero-circuit__wires" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use href="#hc-h-a"/><use href="#hc-h-b"/>
          <use href="#hc-h-c"/><use href="#hc-h-d"/>
        </g>

        <g class="hero-circuit__charges" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use class="hero-circuit__charge hero-circuit__charge--a" href="#hc-h-a"/>
          <use class="hero-circuit__charge hero-circuit__charge--b" href="#hc-h-b"/>
          <use class="hero-circuit__charge hero-circuit__charge--c" href="#hc-h-c"/>
          <use class="hero-circuit__charge hero-circuit__charge--d" href="#hc-h-d"/>
        </g>

        <g class="hero-circuit__nodes">
          <circle class="hero-circuit__node hero-circuit__node--a" cx="206" cy="18" r="4"/>
          <circle class="hero-circuit__node hero-circuit__node--b" cx="384" cy="44" r="3.4"/>
          <circle class="hero-circuit__node hero-circuit__node--c" cx="902" cy="24" r="4"/>
          <circle class="hero-circuit__node hero-circuit__node--d" cx="628" cy="54" r="3.4"/>
          <circle class="hero-circuit__node hero-circuit__node--e" cx="272" cy="28" r="4"/>
          <circle class="hero-circuit__node hero-circuit__node--f" cx="1248" cy="40" r="3.4"/>
        </g>
      </svg>

      <svg class="hero-circuit__edge hero-circuit__edge--bottom" viewBox="0 0 1440 72"
           preserveAspectRatio="none" focusable="false">
        <g class="hero-circuit__wires" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use href="#hc-h-a"/><use href="#hc-h-b"/>
          <use href="#hc-h-c"/><use href="#hc-h-d"/>
        </g>

        <g class="hero-circuit__charges" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use class="hero-circuit__charge hero-circuit__charge--e" href="#hc-h-a"/>
          <use class="hero-circuit__charge hero-circuit__charge--f" href="#hc-h-b"/>
          <use class="hero-circuit__charge hero-circuit__charge--g" href="#hc-h-c"/>
          <use class="hero-circuit__charge hero-circuit__charge--h" href="#hc-h-d"/>
        </g>

        <g class="hero-circuit__nodes">
          <circle class="hero-circuit__node hero-circuit__node--g" cx="206" cy="18" r="4"/>
          <circle class="hero-circuit__node hero-circuit__node--h" cx="384" cy="44" r="3.4"/>
          <circle class="hero-circuit__node hero-circuit__node--i" cx="902" cy="24" r="4"/>
          <circle class="hero-circuit__node hero-circuit__node--j" cx="628" cy="54" r="3.4"/>
          <circle class="hero-circuit__node hero-circuit__node--k" cx="1148" cy="24" r="4"/>
          <circle class="hero-circuit__node hero-circuit__node--l" cx="452" cy="60" r="3.4"/>
        </g>
      </svg>

      <svg class="hero-circuit__edge hero-circuit__edge--left" viewBox="0 0 72 480"
           preserveAspectRatio="none" focusable="false">
        <g class="hero-circuit__wires" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use href="#hc-v-a"/><use href="#hc-v-b"/>
          <use href="#hc-v-c"/><use href="#hc-v-d"/>
        </g>

        <g class="hero-circuit__charges" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use class="hero-circuit__charge hero-circuit__charge--i" href="#hc-v-a"/>
          <use class="hero-circuit__charge hero-circuit__charge--j" href="#hc-v-b"/>
          <use class="hero-circuit__charge hero-circuit__charge--k" href="#hc-v-c"/>
          <use class="hero-circuit__charge hero-circuit__charge--l" href="#hc-v-d"/>
        </g>

        <g class="hero-circuit__nodes">
          <circle class="hero-circuit__node hero-circuit__node--m" cx="18" cy="182" r="4"/>
          <circle class="hero-circuit__node hero-circuit__node--n" cx="48" cy="330" r="3.4"/>
          <circle class="hero-circuit__node hero-circuit__node--o" cx="62" cy="224" r="4"/>
          <circle class="hero-circuit__node hero-circuit__node--p" cx="36" cy="96" r="3.4"/>
        </g>
      </svg>

      <svg class="hero-circuit__edge hero-circuit__edge--right" viewBox="0 0 72 480"
           preserveAspectRatio="none" focusable="false">
        <g class="hero-circuit__wires" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use href="#hc-v-a"/><use href="#hc-v-b"/>
          <use href="#hc-v-c"/><use href="#hc-v-d"/>
        </g>

        <g class="hero-circuit__charges" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use class="hero-circuit__charge hero-circuit__charge--m" href="#hc-v-a"/>
          <use class="hero-circuit__charge hero-circuit__charge--n" href="#hc-v-b"/>
          <use class="hero-circuit__charge hero-circuit__charge--o" href="#hc-v-c"/>
          <use class="hero-circuit__charge hero-circuit__charge--p" href="#hc-v-d"/>
        </g>

        <g class="hero-circuit__nodes">
          <circle class="hero-circuit__node hero-circuit__node--q" cx="18" cy="182" r="4"/>
          <circle class="hero-circuit__node hero-circuit__node--r" cx="48" cy="330" r="3.4"/>
          <circle class="hero-circuit__node hero-circuit__node--s" cx="62" cy="224" r="4"/>
          <circle class="hero-circuit__node hero-circuit__node--t" cx="36" cy="366" r="3.4"/>
        </g>
      </svg>
    </div>
<!--hero-circuit:end-->

<div class="container page-hero__inner">
      <h1 class="page-hero__title"><?= h($data['hero']['title']) ?></h1>
<?php if (trim((string)$data['hero']['subtitle']) !== ''): ?>
      <p class="page-hero__subtitle"><?= h($data['hero']['subtitle']) ?></p>
<?php endif; ?>
    </div>
  </section>

  <!-- ======================== Form + contact info ========================
       The NextJS route pairs the form with an information card; the live page
       leads with the form and lists the three offices below it. Both are kept:
       the pairing for the layout, the live page's content for what goes in it.
       ==================================================================== -->
  <section class="section contact" aria-labelledby="contact-heading">
    <div class="container contact__grid">

      <!-- ------------------------------- Form ------------------------------- -->
      <div data-reveal data-reveal-delay class="contact__form-panel">
        <h2 class="contact__title" id="contact-heading"><?= h($data['form']['title']) ?></h2>
<?php /* Printed unescaped, which is safe for exactly one reason: it went
         through rt_sanitise_html() before it was stored, and that function
         writes its output from an allow-list rather than passing anything
         through. See lib/html.php. */ ?>
<?php if (trim((string)$data['form']['lead']) !== ''): ?>
        <div class="contact__lead"><?= $data['form']['lead'] ?></div>
<?php endif; ?>

        <form
          class="form"
          action="/contact-handler.php"
          method="post"
          novalidate
          data-enhanced-form
          aria-describedby="contact-form-status">

          <div class="field">
            <label class="field__label" for="contact-name">
              Your Name <span class="field__required" aria-hidden="true">*</span>
            </label>
            <input
              class="field__control"
              id="contact-name"
              name="name"
              type="text"
              autocomplete="name"
              required
              aria-describedby="contact-name-error">
            <p class="field__error" id="contact-name-error" role="alert"></p>
          </div>

          <div class="field">
            <label class="field__label" for="contact-phone">
              Your Phone <span class="field__required" aria-hidden="true">*</span>
            </label>
            <input
              class="field__control"
              id="contact-phone"
              name="phone"
              type="tel"
              autocomplete="tel"
              required
              aria-describedby="contact-phone-error contact-phone-hint">
            <p class="field__hint" id="contact-phone-hint">
              Local or international, for example 01712345678 or +8801712345678.
            </p>
            <p class="field__error" id="contact-phone-error" role="alert"></p>
          </div>

          <div class="field">
            <label class="field__label" for="contact-email">
              Your E-Mail <span class="field__required" aria-hidden="true">*</span>
            </label>
            <input
              class="field__control"
              id="contact-email"
              name="email"
              type="email"
              autocomplete="email"
              required
              aria-describedby="contact-email-error">
            <p class="field__error" id="contact-email-error" role="alert"></p>
          </div>

          <div class="field">
            <label class="field__label" for="contact-subject">
              Type of Service <span class="field__required" aria-hidden="true">*</span>
            </label>
            <input
              class="field__control"
              id="contact-subject"
              name="subject"
              type="text"
              list="service-types"
              required
              aria-describedby="contact-subject-error contact-subject-hint">
            <!-- A datalist, not a select: the live field is free text, and a
                 fixed list would turn away work we do but have not listed. -->
            <datalist id="service-types">
<?php foreach ($data['form']['service_types'] as $service): ?>
              <option value="<?= h($service) ?>"></option>
<?php endforeach; ?>
            </datalist>
<?php if (trim((string)$data['form']['subject_hint']) !== ''): ?>
            <p class="field__hint" id="contact-subject-hint">
              <?= h($data['form']['subject_hint']) ?>
            </p>
<?php endif; ?>
            <p class="field__error" id="contact-subject-error" role="alert"></p>
          </div>

          <div class="field">
            <label class="field__label" for="contact-message">
              Message <span class="field__required" aria-hidden="true">*</span>
            </label>
            <textarea
              class="field__control"
              id="contact-message"
              name="message"
              rows="6"
              required
              aria-describedby="contact-message-error"></textarea>
            <p class="field__error" id="contact-message-error" role="alert"></p>
          </div>

          <div class="field field--check">
            <input
              class="field__checkbox"
              id="contact-privacy"
              name="privacy"
              type="checkbox"
              value="yes"
              required
              data-validate="consent"
              aria-describedby="contact-privacy-error">
            <label class="field__label field__label--check" for="contact-privacy">
              I have read and understand the
              <a href="/pages/privacy-policy/">privacy policy</a>.
              <span class="field__required" aria-hidden="true">*</span>
            </label>
            <p class="field__error" id="contact-privacy-error" role="alert"></p>
          </div>

          <!-- The live form uses an image CAPTCHA, which needs a session and a
               server round trip to generate. This is a honeypot instead: real
               visitors never see or tab to it, and the handler drops anything
               that fills it in. -->
          <div class="field field--honeypot" aria-hidden="true">
            <label class="field__label" for="contact-company">Company</label>
            <input
              class="field__control"
              id="contact-company"
              name="company"
              type="text"
              tabindex="-1"
              autocomplete="off">
          </div>

          <p class="form__status" id="contact-form-status" data-form-status role="status" aria-live="polite"></p>

          <button class="btn btn--primary btn--lg btn--block" type="submit">
            <svg class="icon icon--sm" aria-hidden="true" focusable="false"><use href="#paper-plane"></use></svg>
            Send
          </button>

<?php if (trim((string)$data['form']['note']) !== ''): ?>
          <p class="form__note">
            <svg class="icon icon--sm" aria-hidden="true" focusable="false"><use href="#lock"></use></svg>
            <?= h($data['form']['note']) ?>
          </p>
<?php endif; ?>
        </form>
      </div>

      <!-- ---------------------------- Quick contact ---------------------------- -->
      <aside class="contact__aside" aria-labelledby="reach-heading">
        <h2 data-reveal data-reveal-delay class="contact__title" id="reach-heading"><?= h($data['reach']['title']) ?></h2>

<?php /* Every icon a reach row may carry is named in the comment below. Read
         that comment before deleting it: tools/inject_icons.py finds the
         symbols a page needs by scanning it for literal href="#name", and the
         name used here is chosen at run time, where the scan cannot see it.
         This is what keeps the sprite at the top of the page complete. The
         same list is CONTACT_ICONS in lib/contact.php.

         <use href="#envelope"> <use href="#phone"> <use href="#mobile-alt">
         <use href="#clock"> <use href="#map-marker-alt"> <use href="#building">
         <use href="#globe"> <use href="#headset"> <use href="#comment-alt">
         <use href="#paper-plane"> <use href="#calendar-alt">
         <use href="#info-circle"> <use href="#linkedin"> <use href="#github">
      */ ?>
        <ul class="reach" role="list">
<?php foreach ($data['reach']['items'] as $item): ?>
          <li data-reveal data-reveal-delay class="reach__item">
<?php if (isset(CONTACT_ICONS[$item['icon'] ?? ''])): ?>
            <span class="reach__icon">
              <svg class="icon" aria-hidden="true" focusable="false"><use href="#<?= h($item['icon']) ?>"></use></svg>
            </span>
<?php endif; ?>
            <div>
              <h3 class="reach__label"><?= h((string)$item['label']) ?></h3>
<?php /* A row may hold several values — three numbers under one "Phone"
         heading, the way the office cards list theirs. One value stays a
         plain paragraph; more than one becomes a stacked list, so the
         heading is not repeated once per number. */ ?>
<?php if (count($item['values']) > 1): ?>
              <p class="reach__value reach__value--many">
<?php foreach ($item['values'] as $value): ?>
<?php $href = contact_reach_href($item, $value); $external = $href !== null && str_starts_with($href, 'http'); ?>
<?php if ($href !== null): ?>
                <a href="<?= h($href) ?>"<?= $external ? ' rel="noopener noreferrer" target="_blank"' : '' ?>><?= h(contact_reach_text($item, $value)) ?></a>
<?php else: ?>
                <span><?= h(contact_reach_text($item, $value)) ?></span>
<?php endif; ?>
<?php endforeach; ?>
              </p>
<?php else: ?>
<?php $value = (string)($item['values'][0] ?? ''); ?>
<?php $href = contact_reach_href($item, $value); $external = $href !== null && str_starts_with($href, 'http'); ?>
<?php if ($href !== null): ?>
              <p class="reach__value"><a href="<?= h($href) ?>"<?= $external ? ' rel="noopener noreferrer" target="_blank"' : '' ?>><?= h(contact_reach_text($item, $value)) ?></a></p>
<?php else: ?>
              <p class="reach__value"><?= h(contact_reach_text($item, $value)) ?></p>
<?php endif; ?>
<?php endif; ?>
            </div>
          </li>
<?php endforeach; ?>
        </ul>
      </aside>
    </div>
  </section>

  <!-- ============================= Our offices ============================= -->
  <section class="section section--surface offices" aria-labelledby="offices-heading">
    <div class="container">
<?php if (trim((string)$data['offices']['title']) !== '' || trim((string)$data['offices']['lead']) !== ''): ?>
      <div data-reveal data-reveal-delay class="section__header">
<?php if (trim((string)$data['offices']['eyebrow']) !== ''): ?>
        <span class="section__eyebrow"><?= h($data['offices']['eyebrow']) ?></span>
<?php endif; ?>
        <h2 class="section__title" id="offices-heading"><?= h($data['offices']['title']) ?></h2>
<?php if (trim((string)$data['offices']['lead']) !== ''): ?>
        <div class="section__lead"><?= $data['offices']['lead'] ?></div>
<?php endif; ?>
      </div>
<?php endif; ?>

      <ul class="offices__grid" role="list">
<?php foreach ($offices as $office): ?>
        <li data-reveal data-reveal-delay class="office">
          <?= contact_flag_picture($office) ?>
          <h3 class="office__name"><?= h((string)$office['name']) ?></h3>
          <address class="office__body">
<?php if (trim((string)$office['address']) !== ''): ?>
            <p class="office__line">
              <svg class="icon icon--sm office__icon" aria-hidden="true" focusable="false"><use href="#map-marker-alt"></use></svg>
              <?= h((string)$office['address']) ?>
            </p>
<?php endif; ?>
<?php if ($office['phones']): ?>
            <p class="office__line">
              <svg class="icon icon--sm office__icon" aria-hidden="true" focusable="false"><use href="#phone"></use></svg>
              <span class="office__phones">
<?php foreach ($office['phones'] as $phone): ?>
                <a href="tel:<?= h(contact_tel($phone)) ?>"><?= h($phone) ?></a>
<?php endforeach; ?>
              </span>
            </p>
<?php endif; ?>
<?php if (trim((string)$office['hours']) !== ''): ?>
            <p class="office__line">
              <svg class="icon icon--sm office__icon" aria-hidden="true" focusable="false"><use href="#clock"></use></svg>
              <?= h((string)$office['hours']) ?>
            </p>
<?php endif; ?>
          </address>
        </li>
<?php endforeach; ?>
      </ul>
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
              <span class="contact-item__label">Bangladesh</span>
              278/3, Manikdi, Dhaka - 1206<br>
              <span class="contact-item__label">Malaysia</span>
              68100 Batu Caves, Selangor, Malaysia<br>
              <span class="contact-item__label">Belgium</span>
              367, Avenue Louise, Brussels, Belgium
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

<!--dock:start-->
<!-- The small-screen navigation: a floating bar within thumb reach, and a card
     of sections that rises above it. It replaces the header hamburger below
     64em, where the header nav is hidden.

     It sits here, a sibling of <header> and <footer>, and NOT inside the
     header. That is deliberate: .site-header paints a backdrop-filter, and an
     element with one becomes the containing block for its position:fixed
     descendants — which is what clamped the old drawer to the header's own
     box. Keeping this outside the header keeps its containing block the
     viewport. See the note in layout.css.

     The four destinations in the bar are real links, so they still work with
     JavaScript disabled. Only the panel needs script, and the footer carries
     the same links again for that case. -->
<div class="dock" data-dock>
  <div class="dock__panel" id="dock-panel" data-nav-drawer data-open="false">

    <!-- Circuit traces down both edges of the card, drawn rather than
         photographed: about a kilobyte instead of a hundred, it tints itself
         from the theme tokens, and it needs no art direction when the palette
         changes.

         aria-hidden on both: they say nothing, they are texture beside the
         list. The animation is described where it is written, in
         components.css.

         The path data is declared once, in the left column's <defs>. SVG ids
         are document-scoped, so the right column references the same paths and
         is flipped in CSS — one set of geometry, two sides. The two run on
         different durations so the mirror image never moves in lockstep with
         its twin. -->
    <div class="dock__circuit dock__circuit--left" aria-hidden="true">
      <svg viewBox="0 0 80 320" preserveAspectRatio="xMidYMid slice" focusable="false">
        <defs>
          <path id="dock-t-a" d="M10 0v72h34v56h28"/>
          <path id="dock-t-b" d="M10 320v-72h28v-52"/>
          <path id="dock-t-c" d="M34 0v44h32v60"/>
          <path id="dock-t-d" d="M10 128h20v48h32v56"/>
          <path id="dock-t-e" d="M72 320v-44H26v-62"/>
          <path id="dock-t-f" d="M66 0v32H10v64"/>
          <path id="dock-t-g" d="M46 320v-28H10"/>
          <path id="dock-t-h" d="M72 152H50V88"/>
        </defs>

        <g class="dock__wires" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use href="#dock-t-a"/><use href="#dock-t-b"/>
          <use href="#dock-t-c"/><use href="#dock-t-d"/>
          <use href="#dock-t-e"/><use href="#dock-t-f"/>
          <use href="#dock-t-g"/><use href="#dock-t-h"/>
        </g>

        <g class="dock__charges" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use class="dock__charge dock__charge--a" href="#dock-t-a"/>
          <use class="dock__charge dock__charge--b" href="#dock-t-b"/>
          <use class="dock__charge dock__charge--c" href="#dock-t-c"/>
          <use class="dock__charge dock__charge--d" href="#dock-t-d"/>
          <use class="dock__charge dock__charge--e" href="#dock-t-e"/>
          <use class="dock__charge dock__charge--f" href="#dock-t-f"/>
          <use class="dock__charge dock__charge--g" href="#dock-t-g"/>
          <use class="dock__charge dock__charge--h" href="#dock-t-h"/>
        </g>

        <g class="dock__nodes">
          <circle class="dock__node dock__node--a" cx="44" cy="72" r="3.5"/>
          <circle class="dock__node dock__node--b" cx="44" cy="128" r="3"/>
          <circle class="dock__node dock__node--c" cx="66" cy="104" r="3.5"/>
          <circle class="dock__node dock__node--d" cx="30" cy="176" r="3"/>
          <circle class="dock__node dock__node--e" cx="62" cy="232" r="3.5"/>
          <circle class="dock__node dock__node--f" cx="26" cy="214" r="3"/>
          <circle class="dock__node dock__node--g" cx="10" cy="96" r="3.5"/>
          <circle class="dock__node dock__node--h" cx="50" cy="88" r="3"/>
          <circle class="dock__node dock__node--i" cx="38" cy="248" r="3"/>
          <circle class="dock__node dock__node--j" cx="10" cy="292" r="3.5"/>
        </g>
      </svg>
    </div>

    <div class="dock__circuit dock__circuit--right" aria-hidden="true">
      <svg viewBox="0 0 80 320" preserveAspectRatio="xMidYMid slice" focusable="false">
        <g class="dock__wires" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use href="#dock-t-a"/><use href="#dock-t-b"/>
          <use href="#dock-t-c"/><use href="#dock-t-d"/>
          <use href="#dock-t-e"/><use href="#dock-t-f"/>
          <use href="#dock-t-g"/><use href="#dock-t-h"/>
        </g>

        <g class="dock__charges" fill="none" stroke-linecap="round" stroke-linejoin="round">
          <use class="dock__charge dock__charge--k" href="#dock-t-a"/>
          <use class="dock__charge dock__charge--l" href="#dock-t-b"/>
          <use class="dock__charge dock__charge--m" href="#dock-t-c"/>
          <use class="dock__charge dock__charge--n" href="#dock-t-d"/>
          <use class="dock__charge dock__charge--o" href="#dock-t-e"/>
          <use class="dock__charge dock__charge--p" href="#dock-t-f"/>
          <use class="dock__charge dock__charge--q" href="#dock-t-g"/>
          <use class="dock__charge dock__charge--r" href="#dock-t-h"/>
        </g>

        <g class="dock__nodes">
          <circle class="dock__node dock__node--k" cx="44" cy="72" r="3.5"/>
          <circle class="dock__node dock__node--l" cx="44" cy="128" r="3"/>
          <circle class="dock__node dock__node--m" cx="66" cy="104" r="3.5"/>
          <circle class="dock__node dock__node--n" cx="30" cy="176" r="3"/>
          <circle class="dock__node dock__node--o" cx="62" cy="232" r="3.5"/>
          <circle class="dock__node dock__node--p" cx="26" cy="214" r="3"/>
          <circle class="dock__node dock__node--q" cx="10" cy="96" r="3.5"/>
          <circle class="dock__node dock__node--r" cx="50" cy="88" r="3"/>
        </g>
      </svg>
    </div>

    <nav class="dock__nav" aria-label="Site sections">
      <ul class="dock__list">
        <li>
          <a class="dock__item" href="/">
            <span class="dock__item-title">Home</span>
            <span class="dock__item-desc">Start here</span>
          </a>
        </li>
        <li>
          <a class="dock__item" href="/pages/about/">
            <span class="dock__item-title">About Us</span>
            <span class="dock__item-desc">Who we are and how we work</span>
          </a>
        </li>
        <li>
          <a class="dock__item" href="/pages/services/">
            <span class="dock__item-title">Services</span>
            <span class="dock__item-desc">Security, development, cloud and people</span>
          </a>
        </li>
        <li>
          <a class="dock__item" href="/pages/company-profile/">
            <span class="dock__item-title">Company Profile</span>
            <span class="dock__item-desc">Milestones, clients and the technology we use</span>
          </a>
        </li>
        <li>
          <a class="dock__item" href="/pages/careers/">
            <span class="dock__item-title">Careers</span>
            <span class="dock__item-desc">Open roles, and speculative applications</span>
          </a>
        </li>
        <li>
          <a class="dock__item" href="/pages/contact/" aria-current="page">
            <span class="dock__item-title">Contact Us</span>
            <span class="dock__item-desc">Reach us any way you prefer</span>
          </a>
        </li>
      </ul>
    </nav>
  </div>

  <nav class="dock__bar" aria-label="Quick navigation">
    <a class="dock__key" href="/">
      <svg class="icon" aria-hidden="true" focusable="false"><use href="#home"></use></svg>
      <span class="dock__key-label">Home</span>
    </a>
    <a class="dock__key" href="/pages/services/">
      <svg class="icon" aria-hidden="true" focusable="false"><use href="#cogs"></use></svg>
      <span class="dock__key-label">Services</span>
    </a>

    <!-- The one emphasised destination. It is a link like its neighbours, not
         a button: it goes to a page, and a <button> would be a lie to anything
         reading the markup rather than looking at it. The emphasis is the
         filled disc. -->
    <a class="dock__key dock__key--contact" href="/pages/contact/" aria-current="page">
      <span class="dock__key-disc">
        <svg class="icon" aria-hidden="true" focusable="false"><use href="#comment-alt"></use></svg>
      </span>
      <span class="dock__key-label">Contact</span>
    </a>

    <a class="dock__key" href="/pages/company-profile/">
      <svg class="icon" aria-hidden="true" focusable="false"><use href="#building"></use></svg>
      <span class="dock__key-label">Profile</span>
    </a>
    <button
      class="dock__key dock__key--menu"
      type="button"
      data-nav-toggle
      aria-controls="dock-panel"
      aria-expanded="false">
      <svg class="icon dock__icon--open" aria-hidden="true" focusable="false"><use href="#grid-dots"></use></svg>
      <svg class="icon dock__icon--close" aria-hidden="true" focusable="false"><use href="#times"></use></svg>
      <span class="dock__key-label">Menu</span>
    </button>
  </nav>
</div>
<!--dock:end-->

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
