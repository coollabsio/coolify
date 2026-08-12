import createGlobe from 'cobe';

// ISO-3166 alpha-2 -> [lat, lng] centroids (Google public-data canonical set).
// Used to place request-volume markers on the interactive globe. Kept inline so
// the globe has zero runtime fetch dependency.
const CENTROIDS = {"AD":[42.546245,1.601554],"AE":[23.424076,53.847818],"AF":[33.93911,67.709953],"AG":[17.060816,-61.796428],"AI":[18.220554,-63.068615],"AL":[41.153332,20.168331],"AM":[40.069099,45.038189],"AN":[12.226079,-69.060087],"AO":[-11.202692,17.873887],"AQ":[-75.250973,-0.071389],"AR":[-38.416097,-63.616672],"AS":[-14.270972,-170.132217],"AT":[47.516231,14.550072],"AU":[-25.274398,133.775136],"AW":[12.52111,-69.968338],"AZ":[40.143105,47.576927],"BA":[43.915886,17.679076],"BB":[13.193887,-59.543198],"BD":[23.684994,90.356331],"BE":[50.503887,4.469936],"BF":[12.238333,-1.561593],"BG":[42.733883,25.48583],"BH":[25.930414,50.637772],"BI":[-3.373056,29.918886],"BJ":[9.30769,2.315834],"BM":[32.321384,-64.75737],"BN":[4.535277,114.727669],"BO":[-16.290154,-63.588653],"BR":[-14.235004,-51.92528],"BS":[25.03428,-77.39628],"BT":[27.514162,90.433601],"BV":[-54.423199,3.413194],"BW":[-22.328474,24.684866],"BY":[53.709807,27.953389],"BZ":[17.189877,-88.49765],"CA":[56.130366,-106.346771],"CC":[-12.164165,96.870956],"CD":[-4.038333,21.758664],"CF":[6.611111,20.939444],"CG":[-0.228021,15.827659],"CH":[46.818188,8.227512],"CI":[7.539989,-5.54708],"CK":[-21.236736,-159.777671],"CL":[-35.675147,-71.542969],"CM":[7.369722,12.354722],"CN":[35.86166,104.195397],"CO":[4.570868,-74.297333],"CR":[9.748917,-83.753428],"CU":[21.521757,-77.781167],"CV":[16.002082,-24.013197],"CX":[-10.447525,105.690449],"CY":[35.126413,33.429859],"CZ":[49.817492,15.472962],"DE":[51.165691,10.451526],"DJ":[11.825138,42.590275],"DK":[56.26392,9.501785],"DM":[15.414999,-61.370976],"DO":[18.735693,-70.162651],"DZ":[28.033886,1.659626],"EC":[-1.831239,-78.183406],"EE":[58.595272,25.013607],"EG":[26.820553,30.802498],"EH":[24.215527,-12.885834],"ER":[15.179384,39.782334],"ES":[40.463667,-3.74922],"ET":[9.145,40.489673],"FI":[61.92411,25.748151],"FJ":[-16.578193,179.414413],"FK":[-51.796253,-59.523613],"FM":[7.425554,150.550812],"FO":[61.892635,-6.911806],"FR":[46.227638,2.213749],"GA":[-0.803689,11.609444],"GB":[55.378051,-3.435973],"GD":[12.262776,-61.604171],"GE":[42.315407,43.356892],"GF":[3.933889,-53.125782],"GG":[49.465691,-2.585278],"GH":[7.946527,-1.023194],"GI":[36.137741,-5.345374],"GL":[71.706936,-42.604303],"GM":[13.443182,-15.310139],"GN":[9.945587,-9.696645],"GP":[16.995971,-62.067641],"GQ":[1.650801,10.267895],"GR":[39.074208,21.824312],"GS":[-54.429579,-36.587909],"GT":[15.783471,-90.230759],"GU":[13.444304,144.793731],"GW":[11.803749,-15.180413],"GY":[4.860416,-58.93018],"GZ":[31.354676,34.308825],"HK":[22.396428,114.109497],"HM":[-53.08181,73.504158],"HN":[15.199999,-86.241905],"HR":[45.1,15.2],"HT":[18.971187,-72.285215],"HU":[47.162494,19.503304],"ID":[-0.789275,113.921327],"IE":[53.41291,-8.24389],"IL":[31.046051,34.851612],"IM":[54.236107,-4.548056],"IN":[20.593684,78.96288],"IO":[-6.343194,71.876519],"IQ":[33.223191,43.679291],"IR":[32.427908,53.688046],"IS":[64.963051,-19.020835],"IT":[41.87194,12.56738],"JE":[49.214439,-2.13125],"JM":[18.109581,-77.297508],"JO":[30.585164,36.238414],"JP":[36.204824,138.252924],"KE":[-0.023559,37.906193],"KG":[41.20438,74.766098],"KH":[12.565679,104.990963],"KI":[-3.370417,-168.734039],"KM":[-11.875001,43.872219],"KN":[17.357822,-62.782998],"KP":[40.339852,127.510093],"KR":[35.907757,127.766922],"KW":[29.31166,47.481766],"KY":[19.513469,-80.566956],"KZ":[48.019573,66.923684],"LA":[19.85627,102.495496],"LB":[33.854721,35.862285],"LC":[13.909444,-60.978893],"LI":[47.166,9.555373],"LK":[7.873054,80.771797],"LR":[6.428055,-9.429499],"LS":[-29.609988,28.233608],"LT":[55.169438,23.881275],"LU":[49.815273,6.129583],"LV":[56.879635,24.603189],"LY":[26.3351,17.228331],"MA":[31.791702,-7.09262],"MC":[43.750298,7.412841],"MD":[47.411631,28.369885],"ME":[42.708678,19.37439],"MG":[-18.766947,46.869107],"MH":[7.131474,171.184478],"MK":[41.608635,21.745275],"ML":[17.570692,-3.996166],"MM":[21.913965,95.956223],"MN":[46.862496,103.846656],"MO":[22.198745,113.543873],"MP":[17.33083,145.38469],"MQ":[14.641528,-61.024174],"MR":[21.00789,-10.940835],"MS":[16.742498,-62.187366],"MT":[35.937496,14.375416],"MU":[-20.348404,57.552152],"MV":[3.202778,73.22068],"MW":[-13.254308,34.301525],"MX":[23.634501,-102.552784],"MY":[4.210484,101.975766],"MZ":[-18.665695,35.529562],"NA":[-22.95764,18.49041],"NC":[-20.904305,165.618042],"NE":[17.607789,8.081666],"NF":[-29.040835,167.954712],"NG":[9.081999,8.675277],"NI":[12.865416,-85.207229],"NL":[52.132633,5.291266],"NO":[60.472024,8.468946],"NP":[28.394857,84.124008],"NR":[-0.522778,166.931503],"NU":[-19.054445,-169.867233],"NZ":[-40.900557,174.885971],"OM":[21.512583,55.923255],"PA":[8.537981,-80.782127],"PE":[-9.189967,-75.015152],"PF":[-17.679742,-149.406843],"PG":[-6.314993,143.95555],"PH":[12.879721,121.774017],"PK":[30.375321,69.345116],"PL":[51.919438,19.145136],"PM":[46.941936,-56.27111],"PN":[-24.703615,-127.439308],"PR":[18.220833,-66.590149],"PS":[31.952162,35.233154],"PT":[39.399872,-8.224454],"PW":[7.51498,134.58252],"PY":[-23.442503,-58.443832],"QA":[25.354826,51.183884],"RE":[-21.115141,55.536384],"RO":[45.943161,24.96676],"RS":[44.016521,21.005859],"RU":[61.52401,105.318756],"RW":[-1.940278,29.873888],"SA":[23.885942,45.079162],"SB":[-9.64571,160.156194],"SC":[-4.679574,55.491977],"SD":[12.862807,30.217636],"SE":[60.128161,18.643501],"SG":[1.352083,103.819836],"SH":[-24.143474,-10.030696],"SI":[46.151241,14.995463],"SJ":[77.553604,23.670272],"SK":[48.669026,19.699024],"SL":[8.460555,-11.779889],"SM":[43.94236,12.457777],"SN":[14.497401,-14.452362],"SO":[5.152149,46.199616],"SR":[3.919305,-56.027783],"ST":[0.18636,6.613081],"SV":[13.794185,-88.89653],"SY":[34.802075,38.996815],"SZ":[-26.522503,31.465866],"TC":[21.694025,-71.797928],"TD":[15.454166,18.732207],"TF":[-49.280366,69.348557],"TG":[8.619543,0.824782],"TH":[15.870032,100.992541],"TJ":[38.861034,71.276093],"TK":[-8.967363,-171.855881],"TL":[-8.874217,125.727539],"TM":[38.969719,59.556278],"TN":[33.886917,9.537499],"TO":[-21.178986,-175.198242],"TR":[38.963745,35.243322],"TT":[10.691803,-61.222503],"TV":[-7.109535,177.64933],"TW":[23.69781,120.960515],"TZ":[-6.369028,34.888822],"UA":[48.379433,31.16558],"UG":[1.373333,32.290275],"US":[37.09024,-95.712891],"UY":[-32.522779,-55.765835],"UZ":[41.377491,64.585262],"VA":[41.902916,12.453389],"VC":[12.984305,-61.287228],"VE":[6.42375,-66.58973],"VG":[18.420695,-64.639968],"VI":[18.335765,-64.896335],"VN":[14.058324,108.277199],"VU":[-15.376706,166.959158],"WF":[-13.768752,-177.156097],"WS":[-13.759029,-172.104629],"XK":[42.602636,20.902977],"YE":[15.552727,48.516388],"YT":[-12.8275,45.166244],"ZA":[-30.559482,22.937506],"ZM":[-13.133897,27.849332],"ZW":[-19.015438,29.154857]};

// Palettes for the dotted globe, tuned to the analytics chart tokens (blue markers).
const THEMES = {
    dark: {
        dark: 1,
        baseColor: [0.28, 0.32, 0.4],
        markerColor: [0.36, 0.55, 1],
        glowColor: [0.06, 0.09, 0.16],
        mapBrightness: 5,
    },
    light: {
        dark: 0,
        baseColor: [0.86, 0.88, 0.92],
        markerColor: [0.15, 0.39, 0.92],
        glowColor: [1, 1, 1],
        mapBrightness: 8,
    },
};

/**
 * Turn country-breakdown rows into cobe markers. Marker size scales with the
 * square root of request volume so a single dominant country doesn't dwarf the rest.
 *
 * @param {Array<{code: string, requests: number}>} data
 * @returns {Array<{location: [number, number], size: number}>}
 */
function buildMarkers(data) {
    const rows = (data || [])
        .map((r) => ({ code: String(r.code || '').toUpperCase(), requests: Number(r.requests || 0) }))
        .filter((r) => r.requests > 0 && CENTROIDS[r.code]);

    if (rows.length === 0) {
        return [];
    }

    const max = Math.max(...rows.map((r) => r.requests));

    return rows.map((r) => ({
        location: CENTROIDS[r.code],
        size: Math.max(0.03, Math.min(0.11, Math.sqrt(r.requests / max) * 0.11)),
    }));
}

/**
 * Mount an interactive, drag-to-rotate dotted globe onto a canvas. Returns a
 * controller with `update(data, dark)` to swap markers/theme and `destroy()`.
 * The globe auto-rotates until the pointer grabs it, then resumes on release.
 *
 * @param {HTMLCanvasElement} canvas
 * @param {Array<{code: string, requests: number}>} data
 * @param {boolean} dark
 */
function mountTrafficGlobe(canvas, data, dark) {
    let globe = null;
    let width = 0;
    let phi = 0;
    let theta = 0.25;
    let pointerInteracting = null;
    let pointerMovement = 0;
    let destroyed = false;

    const onResize = () => {
        width = canvas.offsetWidth;
    };
    onResize();
    const resizeObserver = new ResizeObserver(onResize);
    resizeObserver.observe(canvas);

    const onPointerDown = (e) => {
        pointerInteracting = e.clientX - pointerMovement;
        canvas.style.cursor = 'grabbing';
    };
    const onPointerUp = () => {
        pointerInteracting = null;
        canvas.style.cursor = 'grab';
    };
    const onPointerMove = (e) => {
        if (pointerInteracting !== null) {
            const delta = e.clientX - pointerInteracting;
            pointerMovement = delta;
            phi = delta / 200;
        }
    };

    canvas.addEventListener('pointerdown', onPointerDown);
    window.addEventListener('pointerup', onPointerUp);
    window.addEventListener('pointermove', onPointerMove);
    canvas.style.cursor = 'grab';

    const create = (rows, isDark) => {
        const theme = isDark ? THEMES.dark : THEMES.light;
        let autoRotate = 0;

        globe = createGlobe(canvas, {
            devicePixelRatio: 2,
            width: width * 2,
            height: width * 2,
            phi: 0,
            theta,
            diffuse: 1.2,
            mapSamples: 16000,
            mapBrightness: theme.mapBrightness,
            dark: theme.dark,
            baseColor: theme.baseColor,
            markerColor: theme.markerColor,
            glowColor: theme.glowColor,
            opacity: 0.9,
            markers: buildMarkers(rows),
            onRender: (state) => {
                if (pointerInteracting === null) {
                    autoRotate += 0.0025;
                }
                state.phi = autoRotate + phi;
                state.width = width * 2;
                state.height = width * 2;
            },
        });
    };

    create(data, dark);

    return {
        update(newData, isDark) {
            if (destroyed) {
                return;
            }
            // cobe fixes markers at creation, so swap by recreating in place.
            if (globe) {
                globe.destroy();
            }
            create(newData, isDark);
        },
        destroy() {
            destroyed = true;
            resizeObserver.disconnect();
            canvas.removeEventListener('pointerdown', onPointerDown);
            window.removeEventListener('pointerup', onPointerUp);
            window.removeEventListener('pointermove', onPointerMove);
            if (globe) {
                globe.destroy();
                globe = null;
            }
        },
    };
}

window.mountTrafficGlobe = mountTrafficGlobe;
