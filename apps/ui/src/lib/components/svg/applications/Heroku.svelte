<script lang="ts">
	export let isAbsolute = true;
</script>

<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" class={isAbsolute ? 'absolute top-0 left-0 -m-4 h-10 w-10' : 'mx-auto w-8 h-8'} viewBox="0 0 72 80">
  <defs>
    <radialGradient id="a" cx="50%" cy="50%" r="74.309%" fx="50%" fy="50%" gradientTransform="matrix(1 0 0 .9 0 .05)">
      <stop offset="0%" stop-color="#844DB8"/>
      <stop offset="100%" stop-color="#5B16A3"/>
    </radialGradient>
    <linearGradient id="c" x1="10.063%" x2="95.86%" y1="94.375%" y2="-.956%">
      <stop offset="0%" stop-color="#7673C0" stop-opacity=".5"/>
      <stop offset="100%" stop-color="#7673C0" stop-opacity="0"/>
    </linearGradient>
    <path id="b" d="M64.8,0 C68.776,0 72,3.224 72,7.2 L72,7.2 L72,72.8 C72,76.776 68.776,80 64.8,80 L64.8,80 L7.2,80 C3.224,80 -7.10542736e-15,76.776 -7.10542736e-15,72.8 L-7.10542736e-15,72.8 L-7.10542736e-15,7.2 C-7.10542736e-15,3.224 3.224,0 7.2,0 L7.2,0 Z M18,52 L18,68 L27,60 L18,52 Z M26,12 L18,12 L18,46.692 L23.651,44.132 L23.7166549,44.1028638 C24.5570898,43.7328438 33.230209,40 41,40 C45.062,40 45.961,42.243 46,44.11 L46,44.11 L46,68 L54.0002258,68 L54.0003112,43.9159307 C53.995191,43.1202378 53.814625,38.91 50.517,35.575 C48.905,33.944 45.945,32 41,32 C35.577,32 29.994,33.411 26,34.711 L26,34.711 L26,12 Z M46,12 L46,12 L45.8889134,12.5431521 C44.958208,16.8809344 43.01824,21.04 40,25 L40,25 L48,25 L48.3558976,24.5891492 C51.7393533,20.6093911 53.478,16.3925333 54,12 L54,12 Z"/>
  </defs>
  <g fill="none">
    <use fill="url(#a)" xlink:href="#b"/>
    <use fill="url(#c)" xlink:href="#b"/>
  </g>
</svg>