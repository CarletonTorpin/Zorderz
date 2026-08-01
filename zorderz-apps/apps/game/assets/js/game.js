/**
 * Zorderz Game — Block Breaker Engine
 *
 * v1.5.2: Scale-to-fit on wide / landscape screens (Prompt 8). On big screens
 *   the width-capped canvas left lots of empty vertical space. The theme now
 *   relaxes the game container's max-height (≥900px / landscape) and publishes
 *   the budget via the CSS var --zdz-game-max-h; the engine letterbox-scales the
 *   EXISTING render (a CSS transform on the canvas wrap) to fill it. Crucially
 *   the canvas BACKING BUFFER is unchanged, so per-frame pixel work — and the
 *   v1.5.1 Sunlight-mode GB budget — are not regressed (a transform is a GPU
 *   composite, effectively free). Input mapping is untouched because
 *   getBoundingClientRect() reports the post-transform box. No-op on phones and
 *   the chat embed (eligibility is decided entirely by the CSS budget var).
 *   A ResizeObserver (+ resize/orientation fallback) re-fits on container
 *   changes and is disconnected on game end.
 *
 * v1.5.1: Sunlight mode performance fix. The Game Boy DMG post-processing
 *   pipeline was running full pixel-loop quantization + 304 grid-line draws
 *   on every frame at 60fps, causing ~12ms/frame overhead on mobile (entire
 *   frame budget on iPhone 3× DPR). Fix: (1) pre-computed 256-entry shade
 *   LUT replaces per-pixel luminance math + 4 comparisons, (2) grid overlay
 *   drawn once to a cached offscreen canvas and composited via drawImage,
 *   (3) GB post-processing throttled to every 2nd frame (authentic 30fps
 *   Game Boy refresh rate) with last-frame caching for skip frames.
 *   Combined: ~12ms/frame → ~1.5ms/frame on iPhone 14 Pro.
 *
 * v1.5.0: Theme-aware rendering. Reads data-theme from <html> for
 *   dark/light/sunlight modes. Sunlight mode: Game Boy DMG 160×144
 *   dot-matrix pipeline (downsample → 4-shade quantize → nearest-neighbor
 *   upscale → grid overlay). Light mode: lighter paddle (#7ab840).
 *   Input/pause system UNCHANGED from v1.4.0.
 *
 * v1.4.0 (Prompt 3A): Remove offensive spiral pattern (G2), diversify
 *   pattern selection with no-repeat tracking (G3).
 *
 * v1.3.0: Crisp canvas, thicker paddle, coherent block colors.
 *   - DPR canvas fix: round CSS dimensions to integers, pin canvas.style
 *     to rounded values, fix scale factor to (dispW*dpr)/W. Eliminates
 *     sub-pixel blur on iPhones (DPR 2× and 3×).
 *   - Paddle: PAD_H 44→52, PY H-56→H-64, roundRect radius 10→8.
 *     Paddle visible above thumb on mobile touch.
 *   - Block colors: patterns redesigned with grouped gradients (2-4 hue
 *     families per pattern). Adjacent blocks share hues. No more rainbow.
 *   - Block height: BH 15→17 for better visibility on small screens.
 *
 * v1.0.5: Bigger ball, symbol power-ups, mouse-leave pause, gold collision fix,
 * HUD outside canvas, A-Z letter patterns, user initial for level 2.
 */
(function () {
	'use strict';

	var W=340,H=290;

	/* ═══ THEME-AWARE COLORS (v1.5.0) ═══
	   Reads data-theme from <html>. Falls back to prefers-color-scheme.
	   All variables match v1.4.0 names exactly — no refactoring needed. */
	var _gbRender=false,_gbCanvas=null,_gbCx=null;
	var GB_W=160,GB_H=144,GB_SHADES=[255,170,85,0];
	var _themeMode='dark';

	/* v1.5.1: GB performance — pre-computed shade LUT, cached grid overlay,
	   frame throttle. Eliminates per-pixel comparisons, 304 line draws/frame,
	   and halves GPU→CPU sync cost by running post-process at 30fps. */
	var _gbLUT=null;           // Uint8Array[256] — luminance → nearest shade
	var _gbGridCanvas=null;    // Static grid overlay (drawn once per resize)
	var _gbGridW=0,_gbGridH=0; // Grid cache dimensions (invalidate on resize)
	var _gbFrameN=0;           // Frame counter for 30fps throttle
	var _gbLastFrame=null;     // Last GB-processed frame (reused on skip frames)

	function _buildGBLUT(){
		_gbLUT=new Uint8Array(256);
		for(var i=0;i<256;i++){
			var best=GB_SHADES[0],bestD=Math.abs(i-GB_SHADES[0]);
			for(var s=1;s<4;s++){var d=Math.abs(i-GB_SHADES[s]);if(d<bestD){best=GB_SHADES[s];bestD=d}}
			_gbLUT[i]=best;
		}
	}
	_buildGBLUT();

	var BG,PAD1,PAD2,BALL_C,TX;
	var PAL,SIL_F,SIL_S,SIL_HI,SIL_HI2,SIL_DMG,SIL_CRK;
	var GLD_F,GLD_S,GLD_HI,GLD_HI2,GLD_DOT,GLD_EDGE;
	var WHT_F,WHT_S,WHT_HI;

	function applyGameTheme(){
		var attr=(document.documentElement.getAttribute('data-theme')||'').toLowerCase();
		if(attr==='sunlight'){_themeMode='sunlight'}
		else if(attr==='light'){_themeMode='light'}
		else if(attr==='dark'){_themeMode='dark'}
		else if(attr==='system'||!attr){_themeMode=window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light'}
		else{_themeMode='dark'}

		if(_themeMode==='sunlight'){
			BG='#ffffff';PAD1='#000000';PAD2='#000000';BALL_C='#000000';
			TX='#000000';
			PAL=[{f:'#000',s:'#000'},{f:'#555',s:'#000'},{f:'#aaa',s:'#555'},{f:'#555',s:'#000'},{f:'#000',s:'#000'},{f:'#555',s:'#000'},{f:'#aaa',s:'#555'},{f:'#000',s:'#000'},{f:'#555',s:'#000'},{f:'#000',s:'#000'},{f:'#aaa',s:'#555'},{f:'#555',s:'#000'},{f:'#000',s:'#000'},{f:'#555',s:'#000'},{f:'#aaa',s:'#555'},{f:'#000',s:'#000'}];
			SIL_F='#aaa';SIL_S='#555';SIL_HI='#fff';SIL_HI2='rgba(255,255,255,.4)';SIL_DMG='rgba(255,255,255,.15)';SIL_CRK='rgba(0,0,0,.3)';
			GLD_F='#555';GLD_S='#000';GLD_HI='#aaa';GLD_HI2='rgba(255,255,255,.3)';GLD_DOT='rgba(255,255,255,.4)';GLD_EDGE='rgba(0,0,0,.15)';
			WHT_F='#fff';WHT_S='#aaa';WHT_HI='rgba(255,255,255,.5)';
			_gbRender=true;
		}else if(_themeMode==='light'){
			BG='#f8f7f4';PAD1='#7ab840';PAD2='#5a9820';BALL_C='#c04520';TX='#444440';
			PAL=[{f:'#AFA9EC',s:'#534AB7'},{f:'#7F77DD',s:'#3C3489'},{f:'#5DCAA5',s:'#0F6E56'},{f:'#1D9E75',s:'#085041'},{f:'#F0997B',s:'#993C1D'},{f:'#D85A30',s:'#712B13'},{f:'#ED93B1',s:'#993556'},{f:'#D4537E',s:'#72243E'},{f:'#85B7EB',s:'#185FA5'},{f:'#378ADD',s:'#0C447C'},{f:'#97C459',s:'#3B6D11'},{f:'#639922',s:'#27500A'},{f:'#FAC775',s:'#854F0B'},{f:'#EF9F27',s:'#633806'},{f:'#F09595',s:'#A32D2D'},{f:'#E24B4A',s:'#791F1F'}];
			SIL_F='#c8c8c8';SIL_S='#a0a0a0';SIL_HI='#eaeaea';SIL_HI2='rgba(255,255,255,.55)';SIL_DMG='rgba(255,255,255,.15)';SIL_CRK='rgba(0,0,0,.15)';
			GLD_F='#c9a030';GLD_S='#8a7020';GLD_HI='#eed050';GLD_HI2='rgba(255,245,170,.55)';GLD_DOT='rgba(255,250,200,.7)';GLD_EDGE='rgba(180,140,30,.12)';
			WHT_F='#f5f5f5';WHT_S='#ccc';WHT_HI='rgba(255,255,255,.7)';
			_gbRender=false;
		}else{
			BG='#1e1e1e';PAD1='#97C459';PAD2='#C0DD97';BALL_C='#F5C4B3';TX='#b4b2a9';
			PAL=[{f:'#AFA9EC',s:'#534AB7'},{f:'#7F77DD',s:'#3C3489'},{f:'#5DCAA5',s:'#0F6E56'},{f:'#1D9E75',s:'#085041'},{f:'#F0997B',s:'#993C1D'},{f:'#D85A30',s:'#712B13'},{f:'#ED93B1',s:'#993556'},{f:'#D4537E',s:'#72243E'},{f:'#85B7EB',s:'#185FA5'},{f:'#378ADD',s:'#0C447C'},{f:'#97C459',s:'#3B6D11'},{f:'#639922',s:'#27500A'},{f:'#FAC775',s:'#854F0B'},{f:'#EF9F27',s:'#633806'},{f:'#F09595',s:'#A32D2D'},{f:'#E24B4A',s:'#791F1F'}];
			SIL_F='#6e6e6e';SIL_S='#444';SIL_HI='#999';SIL_HI2='rgba(255,255,255,.22)';SIL_DMG='rgba(255,255,255,.06)';SIL_CRK='rgba(255,255,255,.18)';
			GLD_F='#a08228';GLD_S='#6a5418';GLD_HI='#ccb040';GLD_HI2='rgba(255,230,120,.25)';GLD_DOT='rgba(255,240,150,.35)';GLD_EDGE='rgba(255,200,60,.12)';
			WHT_F='#d8d8d8';WHT_S='#999';WHT_HI='rgba(255,255,255,.3)';
			_gbRender=false;
		}
		_gbCanvas=null;_gbCx=null;_gbGridCanvas=null;_gbGridW=0;_gbGridH=0;_gbLastFrame=null;_gbFrameN=0; // v1.5.1: Reset all GB caches on theme change
	}
	applyGameTheme(); // Initial theme apply

	var COLS=8,BPAD=3,BTOP=6,PW0=78,PAD_H=52,BALL_R=6.5;
	var BW=Math.floor((W-14-(COLS-1)*BPAD)/COLS),BH=17;
	var BL=Math.floor((W-(COLS*(BW+BPAD)-BPAD))/2);
	var PY=H-64;
	var PUPS=[{t:'wide',c:'#639922',sym:'wide'},{t:'multi',c:'#534AB7',sym:'multi'},{t:'slow',c:'#185FA5',sym:'slow'},{t:'fire',c:'#D85A30',sym:'fire'}];

	/* ═══ A-Z PIXEL FONT 6×6 ═══ */
	var FONT={
		A:['.####.','#....#','#....#','######','#....#','#....#'],
		B:['#####.','#....#','#####.','#....#','#....#','#####.'],
		C:['.#####','#.....','#.....','#.....','#.....','.#####'],
		D:['####..','#...#.','#....#','#....#','#...#.','####..'],
		E:['######','#.....','####..','#.....','#.....','######'],
		F:['######','#.....','####..','#.....','#.....','#.....'],
		G:['.#####','#.....','#..###','#....#','#....#','.####.'],
		H:['#....#','#....#','######','#....#','#....#','#....#'],
		I:['.####.','.#..#.','.#..#.','.#..#.','.#..#.','.####.'],
		J:['..####','....#.','....#.','....#.','#...#.','.###..'],
		K:['#...#.','#..#..','###...','#..#..','#...#.','#....#'],
		L:['#.....','#.....','#.....','#.....','#.....','######'],
		M:['#....#','##..##','#.##.#','#....#','#....#','#....#'],
		N:['#....#','##...#','#.#..#','#..#.#','#...##','#....#'],
		O:['.####.','#....#','#....#','#....#','#....#','.####.'],
		P:['#####.','#....#','#####.','#.....','#.....','#.....'],
		Q:['.####.','#....#','#....#','#..#.#','#...#.','.###.#'],
		R:['#####.','#....#','#####.','#..#..','#...#.','#....#'],
		S:['.#####','#.....','.####.','....#.','....#.','#####.'],
		T:['######','..##..','..##..','..##..','..##..','..##..'],
		U:['#....#','#....#','#....#','#....#','#....#','.####.'],
		V:['#....#','#....#','.#..#.','.#..#.','..##..','..##..'],
		W:['#....#','#....#','#.##.#','#.##.#','##..##','#....#'],
		X:['#....#','.#..#.','..##..','..##..','.#..#.','#....#'],
		Y:['#....#','.#..#.','..##..','..##..','..##..','..##..'],
		Z:['######','....#.','...#..','..#...','.#....','######']
	};
	// v1.3.0: LCOLS — letter background color pairs. Each pair is same-family
	// (light/dark of one hue) for clean single-tone letter backgrounds.
	var LCOLS=[[8,9],[0,1],[2,3],[4,5],[6,7],[10,11],[12,13],[14,15],[2,3],[8,9],[0,1],[4,5],[10,11],[6,7],[14,15],[12,13]];

	function makeLetterPattern(letter,ci){
		var ch=(letter||'A').toUpperCase();var def=FONT[ch]||FONT['A'];
		var pair=LCOLS[ci%LCOLS.length];var c1=pair[0].toString(16),c2=pair[1].toString(16);
		var grid=[];
		for(var r=0;r<6;r++){var bg=r<3?c1:c2;var row=[bg];
			for(var c=0;c<6;c++) row.push(def[r][c]==='#'?'w':bg);
			row.push(bg);grid.push(row)}
		return{key:'letter_'+ch,g:grid};
	}

	// Neutral first-game wall (no company letters). A site may override the
	// welcome pattern by returning a grid of 8-wide row strings from the
	// `zg_first_pattern` PHP filter (surfaced as zgGameData.firstPattern).
	var P_FIRST={key:'first',g:['88bb88bb','bb88bb88','aa22aa22','22aa22aa','99cc99cc','cc99cc99']};
	function firstPattern(){
		var fp=window.zgGameData&&window.zgGameData.firstPattern;
		if(fp&&fp.length&&fp[0]&&fp[0].length>=COLS){return{key:'first',g:fp}}
		return P_FIRST;
	}
	// v1.3.0: Redesigned with grouped color gradients. Each pattern uses
	// 2-4 hue families max. Adjacent blocks share hues. No rainbow cycling.
	// PAL hue pairs: 0,1=purple 2,3=teal 4,5=orange 6,7=pink 8,9=blue a,b=green c,d=gold e,f=red
	var SPATS=[
		{key:'classic',g:[['s','8','8','9','9','8','8','s'],['2','2','3','3','2','2','3','3'],['a','a','a','b','b','a','a','a'],['a','b','b','a','a','b','b','a'],['s','c','c','d','d','c','c','s'],['c','d','c','d','c','d','c','d']]},
		{key:'diamond',g:[['.','.','.','6','7','.','.','.'],['.','.','s','0','1','s','.','.'],  ['.','0','0','1','1','0','0','.'],  ['.','1','0','0','1','1','0','.'],['.','.','s','6','7','s','.','.'],  ['.','.','.','6','7','.','.','.']]},
		{key:'fortress',g:[['g','s','8','8','9','9','s','g'],['s','8','9','9','8','8','9','s'],['.','8','8','9','9','8','8','.'],['.','9','8','8','9','9','8','.'],['s','8','9','8','9','8','9','s'],['g','s','9','9','8','8','s','g']]},
		{key:'chevron',g:[['4','.','.','.','.','.','.','5'],['s','4','.','.','.','.','5','s'],['.','4','4','.','.','5','5','.'],['.','.','4','5','5','4','.','.'],['.','c','d','g','g','c','d','.'],['4','5','4','5','5','4','5','4']]},
		{key:'waves',g:[['8','9','.','.','.','.','2','3'],['.','8','9','.','.','2','3','.'],['.','.','s','8','9','s','.','.'],['.','2','3','.','.','8','9','.'],['2','3','.','.','.','.','8','9'],['.','s','.','g','g','.','s','.']]},
		{key:'columns',g:[['0','.','8','.','a','.','c','.'],['0','s','8','s','a','s','c','s'],['0','.','8','.','a','.','c','.'],['1','.','9','.','b','.','d','.'],['1','s','9','s','b','s','d','s'],['1','.','9','.','b','.','d','.']]},
		{key:'checker',g:[['8','.','9','.','8','.','9','.'],['.','c','.','d','.','c','.','d'],['9','.','8','.','9','.','8','.'],['.','d','.','c','.','d','.','c'],['s','.','s','.','s','.','s','.'],['.','g','.','g','.','g','.','g']]},
		{key:'pyramid',g:[['.','.','.','s','.','.','.','.'],['.','.','e','f','e','.','.','.'],['.','4','4','5','5','4','.','.'],['c','c','d','d','d','d','c','.'],['g','c','c','d','d','c','g','.'],  ['.','.','.','.','.','.','.','.']]},
		{key:'pockets',g:[['s','2','3','.','.','2','3','s'],['2','3','.','.','.','.','2','3'],['3','.','.','g','g','.','.','2'],['3','.','.','g','g','.','.','2'],['2','3','.','.','.','.','2','3'],['s','2','3','.','.','2','3','s']]},
		// v1.1.0: Arkanoid-inspired patterns (v1.3.0: color-redesigned)
		{key:'stairL',g:[['a','.','.','.','.','.','.','.'],[  'a','b','.','.','.','.','.','.'],['.','a','b','.','.','.','.','.'],['.','a','a','b','.','.','.','.'],['.','.',  'a','a','b','.','.','.'],['.','.','.','b','a','b','a','b']]},
		{key:'stairR',g:[['.','.','.','.','.','.','.','9'],['.','.','.','.','.','.',  '8','9'],['.','.','.','.','.',  '8','9','.'],['.','.','.','.','8','8','9','.'],[  '.','.','.',  '8','9','8','.','.'],['9','8','8','9','9','.','.','.','.']]},
		{key:'zigzag',g:[['4','4','5','5','.','.','.','.'],['.','.','.','.','8','8','9','9'],['4','4','5','5','.','.','.','.'],['.','.','.','.','8','8','9','9'],['4','4','5','5','.','.','.','.'],['.','.','.','.','8','8','9','9']]},
		{key:'frame',g:[['s','a','a','b','b','a','a','s'],['a','.','.','.','.','.','.','b'],['a','.','.','.','.','.','.','b'],['b','.','.','.','.','.','.','a'],['b','.','.','.','.','.','.','a'],['s','a','b','a','b','a','b','s']]},
		{key:'cross',g:[['.','.','.','e','f','.','.','.'],  ['.','.','.','e','f','.','.','.'],['8','8','9','s','s','9','8','8'],[  '9','8','8','s','s','8','8','9'],['.','.','.','e','f','.','.','.'],  ['.','.','.','e','f','.','.','.']]},
		{key:'hourglass',g:[['c','c','d','d','d','d','c','c'],['.','c','c','d','d','c','c','.'],['.','.','s','0','1','s','.','.'],['.','.','s','0','1','s','.','.'],  ['.','c','c','d','d','c','c','.'],['c','c','d','d','d','d','c','c']]},
		{key:'arrow_up',g:[['.','.','.','s','.','.','.','.'],['.','.',  's','a','s','.','.','.'],[  '.','s','a','a','b','s','.','.'],['s','a','a','b','b','a','s','.'],['.','.','.','a','b','.','.','.'],['.','.','.','a','b','.','.','.']]},
		{key:'scatter',g:[['8','.','.',  '2','.','.','8','.'],['.','.','2','.','.','9','.','.'],['.','3','.','.',  '8','.','.','2'],['9','.','.',  '3','.','.','2','.'],['.','.',  '8','.','.','3','.','.'],['.','9','.','.',  '2','.','.','3']]},
		{key:'dbldia',g:[['.','s','.','.','.','.','s','.'],['s','6','s','.','.','s','7','s'],['.','s','.','.','.','.','s','.'],  ['.','.','.','s','s','.','.','.'],['.','.',  's','2','3','s','.','.'],  ['.','.','.','s','s','.','.','.']]},
		{key:'vault',g:[['g','g','.','.','.','.','g','g'],['g','8','8','9','9','8','8','g'],['.','8','9','9','8','8','9','.'],  ['.','9','8','8','9','9','8','.'],['g','8','9','8','9','8','9','g'],['g','g','.','.','.','.','g','g']]},
		{key:'teeth',g:[['4','5','.','.','.','.',  '4','5'],['4','5','.','.','.','.','4','5'],['.','.','2','2','3','3','.','.'],['.','.','2','3','3','2','.','.'],['4','5','.','.','.','.',  '4','5'],['4','5','.','.','.','.',  '4','5']]},
		{key:'corridor',g:[['s','s','s','.','.','s','s','s'],['.','.','.','.','.','.','.','.'],['a','a','a','b','b','a','a','a'],['b','b','a','a','a','a','b','b'],['.','.','.','.','.','.','.','.'],['s','s','s','.','.','s','s','s']]},
		{key:'pinwheel',g:[['.','.','4','5','.','.','.','.'],['.','.','.','4','5','.','.','.'],['.','.','.','.','.','8','9','.'],['.','.','.','.','8','9','.','.'],['.','.','8','9','.','.','.','.'],['.','8','9','.','.','.','.','.']]},
		{key:'wings',g:[['.',  '0','.','.','.','.',  '1','.'],[  '0','0','6','.','.','7','1','1'],['0','0','6','s','s','7','1','1'],['.','.',  '6','g','g','7','.','.'],['.','.','.',  'g','g','.','.','.'],['.','.','.','.','.','.','.','.']]},
		{key:'invader',g:[['.','.',  'a','.','.',  'a','.','.'],['.','a','a','b','b','a','a','.'],['a','a',  's','b','b','s','a','a'],['a','b','a','b','b','a','b','a'],['.','e','.','.','.','.',  'e','.'],[  '.','.','e','.','.','e','.','.']]},
	];

	/* ═══ STATE ═══ */
	var canvas,cx;var pad,balls,blocks,parts,pups;
	var score,hi,combo,level,curPatKey;var launched,paused,mousePaused,moved;
	var recentPats=[];  // v2.0 G3: Track recent patterns to prevent repeats
	var mx;var firstGame,animFrame;var concluded=false;var lastPupDrop=0;
	var lastResetTap=0;var resetConfirm=false;var resetFadeout=0;  // v1.2.0: double-tap restart
	var elRoot,elPauseB,elPanelGame,elPanelScores,elScoresBody,elScore,elLevel;
	// v1.5.2 (Prompt 8): scale-to-fit on wide/landscape. We scale the canvas
	// WRAP (not the backing buffer) so per-frame pixel work is unchanged.
	var elCanvasWrap=null;        // .zg-canvas-wrap — the element we transform
	var _fitRO=null;              // ResizeObserver re-fitting on container resize
	var _fitListener=null;        // window resize/orientation handler (RO fallback)
	var _fitRAF=0;                // rAF handle to debounce re-fits
	var _fitting=false;           // reentrancy guard: ignore RO fires we cause ourselves
	var FIT_MAX_SCALE=2.2;        // never enlarge past this (keeps letterbox crisp-ish)

	/* ═══ HELPERS ═══ */
	function parseCell(ch){if(ch==='.'||ch===' ')return null;if(ch==='s')return{t:'silver',ci:0};if(ch==='g')return{t:'gold',ci:0};if(ch==='w')return{t:'white',ci:0};return{t:'normal',ci:parseInt(ch,16)}}
	function mkBall(x,y,dx,dy){return{x:x||W/2,y:y||PY-BALL_R-2,dx:dx||(Math.random()*2-1)*1.6,dy:dy||-4,r:BALL_R,sp:4,fire:false,ft:0,trail:[]}}
	function burst(x,y,c,n){for(var i=0;i<n;i++){var a=Math.random()*Math.PI*2,s=Math.random()*2.2+.6;parts.push({x:x,y:y,dx:Math.cos(a)*s,dy:Math.sin(a)*s,life:1,color:c,r:Math.random()*2+.5})}}
	function dropPup(x,y){var now=Date.now();if(now-lastPupDrop<2500)return;if(Math.random()>0.15)return;lastPupDrop=now;var t=PUPS[Math.floor(Math.random()*PUPS.length)];pups.push({x:x,y:y,dy:1.2,t:t.t,c:t.c,sym:t.sym,r:14})}
	function applyPup(p){if(p.t==='wide'){pad.w=Math.min(140,pad.w+26);setTimeout(function(){pad.w=Math.max(PW0,pad.w-26)},8000)}else if(p.t==='multi'){var b=balls[0]||mkBall();balls.push(mkBall(b.x,b.y,b.dx+1.4,b.dy));balls.push(mkBall(b.x,b.y,b.dx-1.4,b.dy))}else if(p.t==='slow'){balls.forEach(function(b){b.sp=Math.max(2.5,b.sp-.7)});setTimeout(function(){balls.forEach(function(b){b.sp=4})},6000)}else if(p.t==='fire'){balls.forEach(function(b){b.fire=true;b.ft=280;b.trail=[]})}}
	function loadFirstGameFlag(){try{var k='zg_first_game_'+(window.zgGameData?window.zgGameData.userId:'0');return localStorage.getItem(k)!=='done'}catch(e){return true}}
	function markFirstGameDone(){try{var k='zg_first_game_'+(window.zgGameData?window.zgGameData.userId:'0');localStorage.setItem(k,'done')}catch(e){}}
	function getUserInitial(){if(window.zgGameData&&window.zgGameData.userName){var ch=window.zgGameData.userName.charAt(0).toUpperCase();if(FONT[ch])return ch}return null}
	function escH(s){var d=document.createElement('div');d.textContent=s;return d.innerHTML}

	/* ═══ PATTERN SELECTION ═══ */
	function getPattern(){
		if(firstGame) return firstPattern();
		// Level 2: the signed-in user's own first initial when it maps to a glyph;
		// otherwise fall through to a random pattern. No hardcoded fallback letter.
		if(level===2){var ini=getUserInitial();if(ini)return makeLetterPattern(ini,1)}
		// No-repeat pattern selection — avoid the last 3 patterns
		var pick,attempts=0;
		do{pick=SPATS[Math.floor(Math.random()*SPATS.length)];attempts++}while(recentPats.indexOf(pick.key)!==-1&&attempts<10);
		recentPats.push(pick.key);if(recentPats.length>3)recentPats.shift();
		return pick;
	}
	function initBlocks(){blocks=[];var pat=getPattern();curPatKey=pat.key;var g=pat.g;
		for(var r=0;r<g.length;r++)for(var c=0;c<g[r].length;c++){var cell=parseCell(g[r][c]);if(!cell)continue;var hp=cell.t==='normal'||cell.t==='white'?1:cell.t==='silver'?2:999;blocks.push({x:BL+c*(BW+BPAD),y:BTOP+r*(BH+BPAD),w:BW,h:BH,t:cell.t,ci:cell.ci,hp:hp,alive:true,dmg:false})}}

	/* ═══ LIFECYCLE ═══ */
	function respawn(){combo=0;launched=false;moved=false;balls=[mkBall()];pups=[]}
	function reset(){score=0;combo=0;level=1;launched=false;moved=false;mousePaused=false;lastPupDrop=0;resetConfirm=false;recentPats=[];pad={w:PW0,h:PAD_H,x:W/2-PW0/2};balls=[mkBall()];parts=[];pups=[];initBlocks();updateHUD();clearSavedState()}
	function nextLevel(){level++;if(firstGame){firstGame=false;markFirstGameDone()}initBlocks();balls.forEach(function(b){b.sp=Math.min(5.8,b.sp+.15)});updateHUD()}
	function submitScore(){if(!window.zgGameData||!window.zgGameData.restUrl||score<1)return;var xhr=new XMLHttpRequest();xhr.open('POST',window.zgGameData.restUrl);xhr.setRequestHeader('Content-Type','application/json');xhr.setRequestHeader('X-WP-Nonce',window.zgGameData.restNonce);xhr.send(JSON.stringify({score:score,level:level,pattern:curPatKey||'wall'}))}
	function updateHUD(){if(elScore)elScore.textContent=score.toLocaleString();if(elLevel)elLevel.textContent='Lvl '+level}

	/* ═══ GAME STATE PERSISTENCE (v1.1.0) ═══
	 * Saves to sessionStorage so the user can return to their game from
	 * the dashboard tile. State is saved on: navigate-away (concludeGame),
	 * mouse-leave pause, and chat overlay pause. Restored on init if present.
	 */
	var SAVE_KEY = 'zg_game_state_' + (window.zgGameData ? window.zgGameData.userId : '0');

	function saveGameState() {
		if (!launched || score < 1) return;
		try {
			var blockState = blocks.map(function(b){ return { alive:b.alive, hp:b.hp, dmg:b.dmg, t:b.t, ci:b.ci, x:b.x, y:b.y, w:b.w, h:b.h }; });
			var state = {
				score: score, level: level, combo: combo,
				curPatKey: curPatKey, firstGame: firstGame,
				blocks: blockState,
				ball: balls[0] ? { x:balls[0].x, y:balls[0].y } : null,
				padX: pad.x, padW: pad.w,
				speed: balls[0] ? balls[0].sp : 4,
				recentPats: recentPats,  // v2.0 G3: Persist pattern history
				ts: Date.now()
			};
			sessionStorage.setItem(SAVE_KEY, JSON.stringify(state));
		} catch(e) { /* silent — sessionStorage may be unavailable */ }
	}

	function loadGameState() {
		try {
			var raw = sessionStorage.getItem(SAVE_KEY);
			if (!raw) return null;
			var state = JSON.parse(raw);
			// Expire after 30 minutes
			if (Date.now() - state.ts > 30 * 60 * 1000) { sessionStorage.removeItem(SAVE_KEY); return null; }
			return state;
		} catch(e) { return null; }
	}

	function clearSavedState() {
		try { sessionStorage.removeItem(SAVE_KEY); } catch(e) {}
	}

	function restoreFromState(state) {
		score = state.score; level = state.level; combo = state.combo || 0;
		curPatKey = state.curPatKey; firstGame = state.firstGame || false;
		recentPats = state.recentPats || [];  // v2.0 G3: Restore pattern history
		blocks = state.blocks;
		pad = { w: state.padW || PW0, h: PAD_H, x: state.padX || W/2 - PW0/2 };
		// Ball starts resting on paddle — user moves to re-launch
		launched = false; moved = false; mousePaused = false;
		balls = [mkBall()];
		if (state.speed) balls[0].sp = state.speed;
		parts = []; pups = []; lastPupDrop = 0;
		updateHUD();
		clearSavedState();
	}


	/* ═══ LEADERBOARD ═══ */
	function fetchLeaderboard(){if(!window.zgGameData||!window.zgGameData.restUrl||!elScoresBody)return;var xhr=new XMLHttpRequest();xhr.open('GET',window.zgGameData.restUrl);xhr.setRequestHeader('X-WP-Nonce',window.zgGameData.restNonce);xhr.onload=function(){if(xhr.status!==200)return;try{renderLeaderboard(JSON.parse(xhr.responseText))}catch(e){}};xhr.send()}
	function renderLeaderboard(rows){if(!elScoresBody)return;var uid=window.zgGameData?window.zgGameData.userId:0;var html='';rows.forEach(function(r){var rc=r.rank===1?'zg-rank--1':r.rank===2?'zg-rank--2':r.rank===3?'zg-rank--3':'';var self=(r.user_id===uid)?' class="zg-self-row"':'';var d=r.date?r.date.substring(0,10):'';html+='<tr'+self+'><td><span class="zg-rank '+rc+'">'+r.rank+'</span></td><td>'+(r.user_id===uid?'<b>'+escH(r.name)+'</b>':escH(r.name))+'</td><td><b>'+r.score.toLocaleString()+'</b></td><td class="zg-date">'+d+'</td></tr>'});elScoresBody.innerHTML=html}

	/* ═══ DRAWING — BLOCKS ═══ */
	function drawBlock(bl){
		var x=bl.x,y=bl.y,w=bl.w,h=bl.h;
		if(bl.t==='gold'){cx.fillStyle=GLD_F;cx.strokeStyle=GLD_S;cx.lineWidth=1.5;cx.beginPath();cx.roundRect(x,y,w,h,2.5);cx.fill();cx.stroke();cx.beginPath();cx.moveTo(x+1,y+1);cx.lineTo(x+w*.5,y+1);cx.lineTo(x+1,y+h*.5);cx.closePath();cx.fillStyle=GLD_HI2;cx.fill();cx.beginPath();cx.moveTo(x+w*.65,y+h*.6);cx.lineTo(x+w-1,y+h*.6);cx.lineTo(x+w-1,y+h-1);cx.lineTo(x+w*.65,y+h-1);cx.closePath();cx.fillStyle=GLD_EDGE;cx.fill();cx.fillStyle=GLD_DOT;cx.beginPath();cx.arc(x+w*.35,y+h*.38,2,0,Math.PI*2);cx.fill();cx.strokeStyle=GLD_HI;cx.lineWidth=.5;cx.beginPath();cx.moveTo(x+3,y+h-2);cx.lineTo(x+w-3,y+h-2);cx.stroke();return}
		if(bl.t==='silver'){cx.fillStyle=SIL_F;cx.strokeStyle=SIL_S;cx.lineWidth=1;cx.beginPath();cx.roundRect(x,y,w,h,2.5);cx.fill();cx.stroke();cx.beginPath();cx.moveTo(x+1,y+1);cx.lineTo(x+w*.5,y+1);cx.lineTo(x+1,y+h*.5);cx.closePath();cx.fillStyle=bl.dmg?SIL_DMG:SIL_HI2;cx.fill();cx.strokeStyle=SIL_HI;cx.lineWidth=.4;cx.beginPath();cx.moveTo(x+3,y+h-1.5);cx.lineTo(x+w-3,y+h-1.5);cx.stroke();cx.beginPath();cx.moveTo(x+w-1.5,y+2);cx.lineTo(x+w-1.5,y+h-2);cx.stroke();if(bl.dmg){cx.strokeStyle=SIL_CRK;cx.lineWidth=1;cx.beginPath();cx.moveTo(x+w*.28,y);cx.lineTo(x+w*.33,y+h*.35);cx.lineTo(x+w*.45,y+h*.5);cx.lineTo(x+w*.38,y+h*.75);cx.lineTo(x+w*.42,y+h);cx.stroke();cx.beginPath();cx.moveTo(x+w*.33,y+h*.35);cx.lineTo(x+w*.55,y+h*.42);cx.stroke()}return}
		if(bl.t==='white'){cx.fillStyle=WHT_F;cx.strokeStyle=WHT_S;cx.lineWidth=1;cx.beginPath();cx.roundRect(x,y,w,h,2.5);cx.fill();cx.stroke();cx.beginPath();cx.moveTo(x+1,y+1);cx.lineTo(x+w*.45,y+1);cx.lineTo(x+1,y+h*.45);cx.closePath();cx.fillStyle=WHT_HI;cx.fill();return}
		var p=PAL[bl.ci%PAL.length];cx.fillStyle=p.f;cx.strokeStyle=p.s;cx.lineWidth=1;cx.beginPath();cx.roundRect(x,y,w,h,2.5);cx.fill();cx.stroke();
	}

	/* ═══ DRAWING — POWER-UP SYMBOLS (not letters, not circles) ═══ */
	function drawPowerup(p){
		var x=p.x,y=p.y,sz=p.r;
		cx.globalAlpha=0.9;cx.fillStyle=p.c;cx.beginPath();cx.roundRect(x-sz,y-sz*0.7,sz*2,sz*1.4,5);cx.fill();
		cx.globalAlpha=1;cx.fillStyle='#fff';cx.strokeStyle='#fff';cx.lineWidth=2;
		if(p.sym==='wide'){
			cx.beginPath();cx.roundRect(x-8,y-2.5,16,5,2);cx.fill();
			cx.beginPath();cx.moveTo(x-11,y);cx.lineTo(x-7,y-4);cx.lineTo(x-7,y+4);cx.fill();
			cx.beginPath();cx.moveTo(x+11,y);cx.lineTo(x+7,y-4);cx.lineTo(x+7,y+4);cx.fill();
		}
		else if(p.sym==='multi'){
			cx.beginPath();cx.arc(x,y-5,3,0,Math.PI*2);cx.fill();
			cx.beginPath();cx.arc(x-5,y+4,3,0,Math.PI*2);cx.fill();
			cx.beginPath();cx.arc(x+5,y+4,3,0,Math.PI*2);cx.fill();
		}
		else if(p.sym==='fire'){
			cx.beginPath();cx.moveTo(x,y-8);cx.bezierCurveTo(x+7,y-4,x+7,y+3,x+4,y+7);cx.quadraticCurveTo(x+1,y+4,x,y+7);cx.quadraticCurveTo(x-1,y+4,x-4,y+7);cx.bezierCurveTo(x-7,y+3,x-7,y-4,x,y-8);cx.fill();
		}
		else if(p.sym==='slow'){
			cx.beginPath();cx.moveTo(x-5,y-7);cx.lineTo(x+5,y-7);cx.lineTo(x+1.5,y);cx.lineTo(x+5,y+7);cx.lineTo(x-5,y+7);cx.lineTo(x-1.5,y);cx.closePath();cx.fill();
		}
	}

	/* ═══ PHYSICS ═══ */
	function update(){
		if(paused||mousePaused) return;
		pad.x=mx-pad.w/2;pad.x=Math.max(0,Math.min(W-pad.w,pad.x));
		if(!launched){balls[0].x=pad.x+pad.w/2;balls[0].y=PY-BALL_R-2;if(moved)launched=true;return}
		for(var bi=balls.length-1;bi>=0;bi--){
			var b=balls[bi],mg=Math.sqrt(b.dx*b.dx+b.dy*b.dy)||1;b.x+=b.dx/mg*b.sp;b.y+=b.dy/mg*b.sp;
			if(b.fire){b.ft--;b.trail.push({x:b.x,y:b.y,life:1});if(b.trail.length>12)b.trail.shift();b.trail.forEach(function(t){t.life-=.09});if(b.ft<=0){b.fire=false;b.trail=[]}}
			if(b.x-b.r<0){b.x=b.r;b.dx=Math.abs(b.dx)}if(b.x+b.r>W){b.x=W-b.r;b.dx=-Math.abs(b.dx)}if(b.y-b.r<0){b.y=b.r;b.dy=Math.abs(b.dy)}
			if(b.y+b.r>PY&&b.y+b.r<PY+pad.h+4&&b.x>pad.x-3&&b.x<pad.x+pad.w+3&&b.dy>0){b.dy=-Math.abs(b.dy);b.dx=((b.x-(pad.x+pad.w/2))/(pad.w/2))*3.2;b.y=PY-b.r;burst(b.x,b.y,PAD1,2)}
			if(b.y>H+12){balls.splice(bi,1);if(!balls.length)respawn();continue}
			for(var j=0;j<blocks.length;j++){var bl=blocks[j];if(!bl.alive)continue;
				if(b.x+b.r>bl.x&&b.x-b.r<bl.x+bl.w&&b.y+b.r>bl.y&&b.y-b.r<bl.y+bl.h){
					var fL=b.x+b.r-bl.x,fR=(bl.x+bl.w)-(b.x-b.r),fT=b.y+b.r-bl.y,fB=(bl.y+bl.h)-(b.y-b.r);
					var mX=Math.min(fL,fR),mY=Math.min(fT,fB);
					if(bl.t==='gold'){if(mX<mY){b.dx=fL<fR?-Math.abs(b.dx):Math.abs(b.dx);b.x+=fL<fR?-mX-1:mX+1}else{b.dy=fT<fB?-Math.abs(b.dy):Math.abs(b.dy);b.y+=fT<fB?-mY-1:mY+1}burst(bl.x+bl.w/2,bl.y+bl.h/2,GLD_HI,2);continue}
					var fi=b.fire;if(!fi){if(mX<mY){b.dx=fL<fR?-Math.abs(b.dx):Math.abs(b.dx);b.x+=fL<fR?-mX-.5:mX+.5}else{b.dy=fT<fB?-Math.abs(b.dy):Math.abs(b.dy);b.y+=fT<fB?-mY-.5:mY+.5}}
					if(fi)bl.hp=0;else bl.hp--;
					if(bl.hp<=0){bl.alive=false;combo++;score+=10*Math.min(combo,8);if(score>hi)hi=score;updateHUD();var col=bl.t==='silver'?SIL_HI:bl.t==='white'?WHT_F:PAL[bl.ci%PAL.length].f;burst(bl.x+bl.w/2,bl.y+bl.h/2,col,fi?10:7);dropPup(bl.x+bl.w/2,bl.y+bl.h/2)}else{bl.dmg=true;burst(bl.x+bl.w/2,bl.y+bl.h/2,SIL_HI,2)}
					if(!fi)break}}
		}
		for(var i=pups.length-1;i>=0;i--){var p=pups[i];p.y+=p.dy;if(p.y+p.r>PY&&p.y-p.r<PY+pad.h&&p.x>pad.x&&p.x<pad.x+pad.w){applyPup(p);burst(p.x,p.y,p.c,5);pups.splice(i,1);continue}if(p.y>H+12)pups.splice(i,1)}
		for(var k=parts.length-1;k>=0;k--){var pt=parts[k];pt.x+=pt.dx;pt.y+=pt.dy;pt.dy+=.04;pt.life-=.03;if(pt.life<=0)parts.splice(k,1)}
		var rem=0;for(var m=0;m<blocks.length;m++){if(blocks[m].alive&&blocks[m].t!=='gold')rem++}if(rem===0)nextLevel();
	}

	/* ═══ RENDER ═══ */
	function draw(){
		cx.fillStyle=BG;cx.fillRect(0,0,W,H);
		for(var i=0;i<blocks.length;i++)if(blocks[i].alive)drawBlock(blocks[i]);
		var g=cx.createLinearGradient(pad.x,PY,pad.x+pad.w,PY);g.addColorStop(0,PAD1);g.addColorStop(1,PAD2);cx.fillStyle=g;cx.beginPath();cx.roundRect(pad.x,PY,pad.w,pad.h,8);cx.fill();
		for(var bi2=0;bi2<balls.length;bi2++){var b=balls[bi2];if(b.fire){
			// v1.2.0: Flame trail — fading orange embers behind the ball
			for(var ti=0;ti<b.trail.length;ti++){var tr=b.trail[ti];if(tr.life>0){cx.globalAlpha=tr.life*.4;cx.fillStyle='#EF9F27';cx.beginPath();cx.arc(tr.x,tr.y,b.r*tr.life*.8,0,Math.PI*2);cx.fill();cx.fillStyle='#D85A30';cx.beginPath();cx.arc(tr.x+(Math.random()*4-2),tr.y+(Math.random()*4-2),b.r*tr.life*.4,0,Math.PI*2);cx.fill()}}
			cx.globalAlpha=1;
			// Outer glow — orange corona
			cx.fillStyle='rgba(239,159,39,.35)';cx.beginPath();cx.arc(b.x,b.y,b.r+5,0,Math.PI*2);cx.fill();
			// Flame flicker — 3 small random fire wisps above the ball
			for(var fi=0;fi<3;fi++){var fx=b.x+(Math.random()*8-4),fy=b.y-b.r-(Math.random()*6+2),fr=Math.random()*2.5+1;cx.fillStyle=fi===0?'#FAC775':'#EF9F27';cx.globalAlpha=0.6+Math.random()*0.4;cx.beginPath();cx.arc(fx,fy,fr,0,Math.PI*2);cx.fill()}
			cx.globalAlpha=1;
			// Hot core — bright yellow center
			cx.fillStyle='#D85A30';cx.beginPath();cx.arc(b.x,b.y,b.r+1,0,Math.PI*2);cx.fill();
			cx.fillStyle='#FAC775';cx.beginPath();cx.arc(b.x,b.y,b.r-1.5,0,Math.PI*2);cx.fill();
			cx.fillStyle='#FFF3D6';cx.beginPath();cx.arc(b.x,b.y,b.r-3.5,0,Math.PI*2);cx.fill();
		}else{cx.fillStyle=BALL_C;cx.beginPath();cx.arc(b.x,b.y,b.r,0,Math.PI*2);cx.fill()}}
		for(var pi=0;pi<pups.length;pi++)drawPowerup(pups[pi]);
		for(var pk=0;pk<parts.length;pk++){var pt=parts[pk];cx.globalAlpha=pt.life;cx.fillStyle=pt.color;cx.beginPath();cx.arc(pt.x,pt.y,pt.r,0,Math.PI*2);cx.fill()}cx.globalAlpha=1;
		if(mousePaused&&!paused){
			cx.fillStyle='rgba(0,0,0,.45)';cx.fillRect(0,0,W,H);
			cx.fillStyle='#fff';cx.font='600 16px -apple-system,sans-serif';cx.textAlign='center';
			cx.fillText('Paused',W/2,H/2-20);
			cx.font='400 12px -apple-system,sans-serif';cx.fillStyle='rgba(255,255,255,.6)';
			cx.fillText('Score: '+score.toLocaleString()+' \u00B7 Level '+level,W/2,H/2+4);
			cx.fillText('Move back to resume',W/2,H/2+24);
		}
		if(!launched&&!paused&&!mousePaused){cx.fillStyle=TX;cx.font='11px -apple-system,BlinkMacSystemFont,sans-serif';cx.textAlign='center';cx.fillText('Move to launch',W/2,PY-50)}
		// v1.2.0: Restart icon — subtle ↺ in bottom-left, double-tap to activate
		if(launched||score>0){
			var now=Date.now();if(resetConfirm&&now-lastResetTap>2000)resetConfirm=false;
			cx.save();cx.globalAlpha=resetConfirm?0.8:0.2;cx.fillStyle='#fff';cx.font='14px -apple-system,sans-serif';cx.textAlign='left';
			cx.fillText('\u21BA',6,H-6);
			if(resetConfirm){cx.font='9px -apple-system,sans-serif';cx.globalAlpha=0.5;cx.fillText('tap again',20,H-6)}
			cx.restore();
		}

		// v1.5.1: Game Boy DMG post-processing (sunlight mode only)
		// Optimized: pre-computed LUT replaces per-pixel math, grid overlay
		// drawn once and cached, processing throttled to 30fps (authentic GB
		// refresh rate). Eliminates ~12ms/frame overhead on mobile.
		if(_gbRender){
			_gbFrameN++;
			var runGB=(_gbFrameN%2===0); // 30fps: process every 2nd frame
			if(runGB){
				if(!_gbCanvas){_gbCanvas=document.createElement('canvas');_gbCanvas.width=GB_W;_gbCanvas.height=GB_H;_gbCx=_gbCanvas.getContext('2d')}
				cx.save();cx.setTransform(1,0,0,1,0,0);
				_gbCx.imageSmoothingEnabled=true;_gbCx.drawImage(canvas,0,0,GB_W,GB_H);
				var imgData=_gbCx.getImageData(0,0,GB_W,GB_H),px=imgData.data;
				// LUT quantization — single array lookup per pixel, no comparisons
				for(var pi2=0;pi2<px.length;pi2+=4){
					var shade=_gbLUT[((px[pi2]*77+px[pi2+1]*150+px[pi2+2]*29)>>8)];
					px[pi2]=px[pi2+1]=px[pi2+2]=shade;
				}
				_gbCx.putImageData(imgData,0,0);
				cx.imageSmoothingEnabled=false;cx.clearRect(0,0,canvas.width,canvas.height);
				cx.drawImage(_gbCanvas,0,0,canvas.width,canvas.height);
				// Cached grid overlay — 304 line segments drawn once, composited via drawImage
				if(!_gbGridCanvas||_gbGridW!==canvas.width||_gbGridH!==canvas.height){
					_gbGridCanvas=document.createElement('canvas');_gbGridCanvas.width=canvas.width;_gbGridCanvas.height=canvas.height;
					var gc=_gbGridCanvas.getContext('2d');
					var stepX=canvas.width/GB_W,stepY=canvas.height/GB_H;
					gc.strokeStyle='rgba(0,0,0,0.1)';gc.lineWidth=0.5;gc.beginPath();
					for(var gx2=0;gx2<canvas.width;gx2+=stepX){gc.moveTo(gx2,0);gc.lineTo(gx2,canvas.height)}
					for(var gy2=0;gy2<canvas.height;gy2+=stepY){gc.moveTo(0,gy2);gc.lineTo(canvas.width,gy2)}
					gc.stroke();_gbGridW=canvas.width;_gbGridH=canvas.height;
				}
				cx.drawImage(_gbGridCanvas,0,0);
				cx.restore();
				// Snapshot for skip-frames (only recreate backing store on resize)
				if(!_gbLastFrame||_gbLastFrame.width!==canvas.width||_gbLastFrame.height!==canvas.height){
					_gbLastFrame=document.createElement('canvas');_gbLastFrame.width=canvas.width;_gbLastFrame.height=canvas.height;
				}
				_gbLastFrame.getContext('2d').drawImage(canvas,0,0);
			}else if(_gbLastFrame){
				// Skip frame — composite cached result (no getImageData, no grid draw)
				cx.save();cx.setTransform(1,0,0,1,0,0);cx.clearRect(0,0,canvas.width,canvas.height);
				cx.drawImage(_gbLastFrame,0,0);cx.restore();
			}
		}
	}

	/* ═══ LOOP + CONCLUDE ═══ */
	function loop(){if(canvas&&!canvas.isConnected){concludeGame();return}update();draw();animFrame=requestAnimationFrame(loop)}
	function concludeGame(){if(concluded)return;concluded=true;if(animFrame){cancelAnimationFrame(animFrame);animFrame=null}teardownFit();if(score>hi)hi=score;saveGameState();submitScore()}

	/* ═══ INPUT (v1.0.5: mouseleave/mouseenter pause) ═══ */
	function onMove(clientX){if(!canvas)return;var r=canvas.getBoundingClientRect();mx=(clientX-r.left)*(W/r.width);if(!moved&&!paused&&!mousePaused)moved=true;if(mousePaused)mousePaused=false}
	function bindInput(){
		canvas.addEventListener('mousemove',function(e){onMove(e.clientX)});
		canvas.addEventListener('touchmove',function(e){e.preventDefault();onMove(e.touches[0].clientX)},{passive:false});
		canvas.addEventListener('touchstart',function(e){var r=canvas.getBoundingClientRect();mx=(e.touches[0].clientX-r.left)*(W/r.width);if(mousePaused)mousePaused=false;
			// v1.2.0: Check if tap is on restart icon (bottom-left 30x20 area)
			var ty=(e.touches[0].clientY-r.top)*(H/r.height),tx=(e.touches[0].clientX-r.left)*(W/r.width);
			if(tx<30&&ty>H-20&&(launched||score>0)){handleResetTap();e.preventDefault();return}
		},{passive:false});
		canvas.addEventListener('click',function(e){
			// v1.2.0: Check if click is on restart icon
			var r=canvas.getBoundingClientRect();var cx2=(e.clientX-r.left)*(W/r.width),cy2=(e.clientY-r.top)*(H/r.height);
			if(cx2<30&&cy2>H-20&&(launched||score>0)){handleResetTap();return}
		});
		canvas.addEventListener('mouseleave',function(){if(launched&&!paused){mousePaused=true;saveGameState()}});
		canvas.addEventListener('mouseenter',function(){if(mousePaused)mousePaused=false});
	}
	// v1.2.0: Double-tap restart handler
	function handleResetTap(){
		var now=Date.now();
		if(resetConfirm&&now-lastResetTap<2000){
			// Second tap within 2s — reset
			resetConfirm=false;
			submitScore();
			reset();
		}else{
			// First tap — enter confirm state
			resetConfirm=true;
			lastResetTap=now;
		}
	}

	/* ═══ TABS ═══ */
	function bindTabs(rootEl){if(!elRoot)return;var tabs=elRoot.querySelectorAll('.zg-tab');tabs.forEach(function(btn){btn.addEventListener('click',function(){tabs.forEach(function(b){b.classList.remove('zg-tab--active')});btn.classList.add('zg-tab--active');var t=btn.getAttribute('data-tab');if(elPanelGame)elPanelGame.style.display=t==='game'?'block':'none';if(elPanelScores)elPanelScores.style.display=t==='scores'?'block':'none';if(t==='scores')fetchLeaderboard()})})}

	/* ═══ SCALE-TO-FIT (v1.5.2 — Prompt 8) ═══
	 * On wide / landscape screens the game's canvas is width-capped (340→680px),
	 * leaving lots of empty vertical space. The THEME relaxes the game
	 * container's max-height on ≥900px / landscape and publishes the available
	 * height as the CSS var --zdz-game-max-h. Our job is to letterbox the
	 * EXISTING render up to fill that height.
	 *
	 * Why a CSS transform and not a bigger canvas:
	 *   The backing buffer stays exactly the width-driven size it already was,
	 *   so the number of pixels drawn per frame — and the Sunlight-mode GB
	 *   post-processing budget fixed in v1.5.1 — are completely unchanged. A
	 *   transform: scale() is a GPU composite, effectively free per frame.
	 *
	 * Why scale the WRAP and not the <canvas>:
	 *   The pause banner and the chat-results overlay are children of the wrap,
	 *   so they scale together and stay aligned. Input mapping also keeps
	 *   working untouched: onMove() derives the playfield x from
	 *   canvas.getBoundingClientRect(), which already reports the POST-transform
	 *   box — so a scaled wrap needs no input-code changes.
	 *
	 * Eligibility lives entirely in CSS: --zdz-game-max-h is 0 unless a media
	 * query (≥900px, or landscape with enough height, and never data-mode=chat)
	 * raised it. So here we just read the budget; if it's 0 (or smaller than the
	 * natural height) we sit at scale 1 — i.e. phones and the chat embed are
	 * unaffected by construction, with no duplicated breakpoint logic.
	 */
	function readGameMaxH(){
		if(!elCanvasWrap)return 0;
		// Resolve --zdz-game-max-h to PIXELS. Reading a custom property directly
		// (getComputedStyle().getPropertyValue('--zdz-game-max-h')) returns the
		// *specified token string* — e.g. "82vh" or "calc(...)", NOT a resolved
		// px value — so parseFloat() would mis-read viewport/relative units. To
		// force resolution we set a real length property (max-height) referencing
		// the var on a probe element placed inside the wrap (so it inherits the
		// same cascade, including any theme override), then read the computed px.
		var probe=document.createElement('div');
		probe.style.cssText='position:absolute;left:-9999px;top:0;width:0;visibility:hidden;pointer-events:none;max-height:var(--zdz-game-max-h,0px)';
		elCanvasWrap.appendChild(probe);
		var px=parseFloat(getComputedStyle(probe).maxHeight);
		elCanvasWrap.removeChild(probe);
		// A 'none' max-height (var unset/invalid) or non-finite → no budget.
		// Note: px and vh budgets resolve correctly here (vh is viewport-based);
		// the theme and our fallbacks use only those. A %-based budget would
		// resolve against the probe's containing block, not the game container,
		// so the theme should express --zdz-game-max-h in px or vh.
		return isFinite(px)&&px>0?px:0;
	}
	function clearFit(){
		if(!elCanvasWrap)return;
		elCanvasWrap.style.transform='';
		elCanvasWrap.style.marginBottom='';
	}
	function fitToContainer(){
		if(_fitting){scheduleFit();return;} // busy: retry next frame, never drop a real resize
		if(!elCanvasWrap||!canvas||!canvas.isConnected)return;

		_fitting=true;
		try{
			var maxH=readGameMaxH();
			// Measure the natural (un-transformed, width-driven) box first.
			var prevTransform=elCanvasWrap.style.transform;
			elCanvasWrap.style.transform='none';
			var natRect=elCanvasWrap.getBoundingClientRect();
			var naturalH=natRect.height;
			var naturalW=natRect.width;
			elCanvasWrap.style.transform=prevTransform; // restore (overwritten below)

			// v1.5.4: the theme derives --zdz-game-max-h from WIDTH-based media queries,
			// so on a wide-but-short desktop window that fixed budget can exceed the real
			// vertical room and push the bottom of the game (the paddle) past the visible
			// area -- "larger than the portal." Clamp the budget to the actual space from
			// the game's top edge to the bottom of the viewport (minus a safety inset) so
			// the game ALWAYS fits on screen and stays fully playable. transform-origin is
			// top center, so the top edge is the anchor and all growth is downward here.
			var FIT_SAFE_PX=10;
			var vpH=window.innerHeight||document.documentElement.clientHeight||0;
			if(maxH>0&&vpH>0){
				var roomBelowTop=vpH-natRect.top-FIT_SAFE_PX;
				if(roomBelowTop<=0){clearFit();return;}
				if(maxH>roomBelowTop){maxH=roomBelowTop;} // never exceed real on-screen room
			}

			if(maxH<=0||naturalH<=0||maxH<=naturalH+1){
				// No usable extra budget → stay at native size (phones/portrait).
				clearFit();
				return;
			}

			// Height-driven scale: fill the container's height budget.
			var kH=maxH/naturalH;

			// Width clamp: the widget body is `overflow: clip` with children
			// capped at 100% width (the V9.1 overflow contract), so a transform
			// that grows the wrap WIDER than its container would be clipped on
			// the sides. Cap the scale so the enlarged width still fits the
			// available container width. We scale-to-CONTAIN: min(height, width).
			//
			// The right "available width" is the widget body / app container —
			// NOT .zg-panel--game (a flex item that shrink-wraps to the capped
			// canvas, which would report ~naturalW and pin the scale to 1×). We
			// measure .zg-wrap's parent: .zg-wrap is `margin: 0 auto` and full-
			// width on wide screens, so its parent's content box is the true
			// horizontal room the scaled game may occupy.
			var avail=0;
			var host=(elRoot&&elRoot.parentElement)?elRoot.parentElement:elCanvasWrap.parentElement;
			if(host){
				var cs=getComputedStyle(host);
				var padX=(parseFloat(cs.paddingLeft)||0)+(parseFloat(cs.paddingRight)||0);
				avail=host.clientWidth-padX;
			}
			// v1.5.4: never let the scaled width exceed the viewport width either.
			var vpW=window.innerWidth||document.documentElement.clientWidth||0;
			if(vpW>0){var vpAvail=vpW-2*FIT_SAFE_PX;if(avail<=0||avail>vpAvail)avail=vpAvail;}
			var kW=(avail>0&&naturalW>0)?(avail/naturalW):Infinity;

			var k=Math.min(kH,kW);
			if(k>FIT_MAX_SCALE)k=FIT_MAX_SCALE;
			if(k<=1.001){clearFit();return;}

			// Round to 3dp to avoid jitter from sub-pixel rect noise on re-fit.
			k=Math.round(k*1000)/1000;
			elCanvasWrap.style.transform='scale('+k+')';
			// A transform doesn't reflow, so the enlarged game would overlap content
			// below it. Reserve the grown height in the flow via margin-bottom.
			var grown=naturalH*(k-1);
			elCanvasWrap.style.marginBottom=Math.round(grown)+'px';
		}finally{
			// Release on the NEXT frame so the RO callback fired by our margin
			// write (same tick) still sees the guard up and is ignored.
			requestAnimationFrame(function(){_fitting=false;});
		}
	}
	function scheduleFit(){
		if(_fitRAF)cancelAnimationFrame(_fitRAF);
		_fitRAF=requestAnimationFrame(function(){_fitRAF=0;fitToContainer();});
	}
	function setupFit(){
		teardownFit();
		// Re-fit when the container resizes (sidebar open/close, window resize,
		// the theme toggling its container budget). ResizeObserver is ideal;
		// fall back to window resize/orientation for very old WebViews.
		try{
			if(typeof ResizeObserver!=='undefined'&&elCanvasWrap&&elCanvasWrap.parentElement){
				_fitRO=new ResizeObserver(scheduleFit);
				_fitRO.observe(elCanvasWrap.parentElement);
			}
		}catch(e){_fitRO=null;}
		_fitListener=function(){scheduleFit();};
		window.addEventListener('resize',_fitListener);
		window.addEventListener('orientationchange',_fitListener);
	}
	function teardownFit(){
		if(_fitRO){try{_fitRO.disconnect();}catch(e){}_fitRO=null;}
		if(_fitListener){
			window.removeEventListener('resize',_fitListener);
			window.removeEventListener('orientationchange',_fitListener);
			_fitListener=null;
		}
		if(_fitRAF){cancelAnimationFrame(_fitRAF);_fitRAF=0;}
	}

	/* ═══ INIT ═══ */
	function init(rootEl){
		if(animFrame){cancelAnimationFrame(animFrame);animFrame=null}concluded=false;
		var root=rootEl||document;canvas=root.querySelector('#zg-canvas');if(!canvas)return;

		// v1.5.2: Capture the wrap and clear any prior scale-to-fit transform
		// BEFORE measuring the canvas below. The backing buffer must be sized
		// from the NATURAL (un-transformed) rect — otherwise a leftover scale
		// from a previous mount would inflate canvas.width and regress the
		// per-frame pixel budget. The fit transform is (re)applied at the end.
		elCanvasWrap=root.querySelector('#zg-canvas-wrap');
		if(elCanvasWrap){elCanvasWrap.style.transform='';elCanvasWrap.style.marginBottom='';}

		// v1.3.0: Crisp canvas — round to nearest physical pixel boundary.
		// v1.2.0 bug: scale factor was (dispW / W) * dpr which double-applies
		// the size difference when dispW ≠ W. The backing buffer is already
		// sized to dispW * dpr, so the context scale just needs to map
		// logical W×H to (dispW*dpr)×(dispH*dpr). Also pin canvas.style
		// to rounded integers to prevent browser sub-pixel CSS scaling.
		var dpr = window.devicePixelRatio || 1;
		var rect = canvas.getBoundingClientRect();
		var dispW = Math.round(rect.width) || W;
		var dispH = Math.round(rect.height) || H;
		canvas.style.width = dispW + 'px';
		canvas.style.height = dispH + 'px';
		canvas.width = dispW * dpr;
		canvas.height = dispH * dpr;
		cx = canvas.getContext('2d');
		cx.scale((dispW * dpr) / W, (dispH * dpr) / H);
		cx.imageSmoothingEnabled = false;

		elRoot=root.querySelector('#zg-game-root');elPauseB=root.querySelector('#zg-pause-banner');
		elPanelGame=root.querySelector('#zg-panel-game');elPanelScores=root.querySelector('#zg-panel-scores');
		elScoresBody=root.querySelector('#zg-scores-body');
		elScore=root.querySelector('#zg-hud-score');elLevel=root.querySelector('#zg-hud-level');
		mx=W/2;hi=0;firstGame=loadFirstGameFlag();
		bindInput();bindTabs(root);
		// v1.1.0: Auto-restore saved game state if available
		var saved = loadGameState();
		if (saved && saved.blocks && saved.blocks.length > 0) {
			restoreFromState(saved);
		} else {
			reset();
		}
		// v1.5.2: Now that layout is settled, letterbox-scale the wrap to fill
		// the container the theme allows (wide/landscape only), and keep it
		// fitted across resizes/rotations. No-op on phones and the chat embed.
		setupFit();
		scheduleFit();
		loop();
	}

	function autoInit(){init();if(canvas)return;var a=0,mx=300;var p=setInterval(function(){a++;if(document.querySelector('#zg-canvas')){clearInterval(p);init()}else if(a>=mx){clearInterval(p)}},200)}
	if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',autoInit)}else{autoInit()}
	window.addEventListener('beforeunload',function(){if(canvas&&canvas.isConnected&&score>0&&!concluded)concludeGame()});

	/* v1.5.0: Watch for theme changes */
	if(typeof MutationObserver!=='undefined'){
		new MutationObserver(function(muts){
			muts.forEach(function(m){if(m.attributeName==='data-theme')applyGameTheme()});
		}).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
	}
})();
