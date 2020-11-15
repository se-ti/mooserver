/**
 * Copyright 2010 Sun Ning <classicning@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */
onmessage = function(e){
    calc(e.data);
}

function calc(params) {
    var value = params.value || {};
    var degree = params.degree || 1;
    var deg2 = degree / 2;

    var d0 = new Date();
    for(var pos in params.data){
        var data = params.data[pos];
        var radius = Math.pow((data / params.step), 1/degree);
        var radiusSq = Math.pow(radius, 2);
        radius = Math.floor(radius);

        var x = Math.floor(pos%params.width);
        var y = Math.floor(pos/params.width);

        var maxY = y+radius < params.height ? y+radius : params.height - 1;
        for (var scany = y - radius; scany <= maxY; scany++) {
            if (scany < 0)
                continue;

            var dySq = Math.pow(scany-y, 2);
            var scanline = scany*params.width;

            var rx = Math.floor(Math.sqrt(radiusSq - dySq));
            var maxX = rx < params.width - x ? x + rx : (params.width - 1);
            for (var scanx = x - rx; scanx <= maxX; scanx++) {
                if (scanx < 0)
                    continue;

                var v = data - params.step * Math.pow(Math.pow(scanx - x, 2) + dySq, deg2);
                var id = scanx + scanline;

                if (value[id])
                    value[id] += v;
                else
                    value[id] = v;
            }
        }
    }

    console.log('worker', 'in:', new Date() - d0);
    postMessage({'value': value, 'width': params.width, 'height': params.height});
}
