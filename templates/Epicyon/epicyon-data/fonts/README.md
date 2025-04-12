# Epicyon Custom Fonts

Add any fonts that you may want to use within themes to this directory. They can be in ttf, woff or woff2 format.

Within your CSS include the font with:

``` css
@font-face {
  font-family: 'Your Font Name';
  font-style: normal;
  font-weight: normal;
  font-display: swap;
  src: url('./fonts/yourfont.woff2') format('woff2'),
    url('./fonts/yourfont.woff') format('woff'),
    url('./fonts/yourfont.ttf') format('truetype');
}
```

Then you can reference it later with:

``` css
font-family: 'Your Font Name', serif;
```
