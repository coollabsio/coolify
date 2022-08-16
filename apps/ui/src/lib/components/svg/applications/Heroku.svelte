<script lang="ts">
	export let isAbsolute = true;
</script>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" 	class={isAbsolute ? 'absolute top-0 left-0 -m-4 h-10 w-10' : 'mx-auto w-8 h-8'} viewBox="0 0 72 80">
    <defs>
      <radialGradient id="a" cx="50%" cy="50%" r="74.309%" fx="50%" fy="50%" gradientTransform="matrix(1 0 0 .89474 0 .053)">
        <stop offset="0%" stop-color="#844DB8"/>
        <stop offset="100%" stop-color="#5B16A3"/>
      </radialGradient>
      <linearGradient id="c" x1="10.528%" x2="95.325%" y1="94.375%" y2="-.956%">
        <stop offset="0%" stop-color="#7673C0" stop-opacity=".5"/>
        <stop offset="100%" stop-color="#7673C0" stop-opacity="0"/>
      </linearGradient>
      <rect id="b" width="68" height="76" x="2" y="2" rx="5"/>
    </defs>
    <g fill="none" fill-rule="evenodd">
      <g fill-rule="nonzero">
        <use fill="url(#a)" xlink:href="#b"/>
        <use fill="url(#c)" xlink:href="#b"/>
      </g>
      <path fill="#FFF" d="M64.8,0 C68.776,0 72,3.224 72,7.2 L72,7.2 L72,72.8 C72,76.776 68.776,80 64.8,80 L64.8,80 L7.2,80 C3.224,80 0,76.776 0,72.8 L0,72.8 L0,7.2 C0,3.224 3.224,0 7.2,0 L7.2,0 Z M64.8,4 L7.2,4 C5.436,4 4,5.435 4,7.2 L4,7.2 L4,72.8 C4,74.564 5.436,76 7.2,76 L7.2,76 L64.8,76 C66.565,76 68,74.564 68,72.8 L68,72.8 L68,7.2 C68,5.435 66.565,4 64.8,4 L64.8,4 Z M19,52 L28,60 L19,68 L19,52 Z M27,12 L27,34.711 C30.994,33.411 36.577,32 42,32 C46.945,32 49.905,33.944 51.517,35.575 C54.814625,38.91 54.995191,43.1202378 55.0003112,43.9159307 L55.0002258,68 L47,68 L47,44.11 C46.961,42.243 46.062,40 42,40 C34.230209,40 25.5570898,43.7328438 24.7166549,44.1028638 L24.651,44.132 L19,46.692 L19,12 L27,12 Z M55,12 C54.46,16.544 52.618,20.9 49,25 L49,25 L41,25 C44.144,20.875 46.118,16.534 47,12 L47,12 Z"/>
    </g>
  </svg>