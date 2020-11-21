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
    var value = params.value || (new Array(params.width * params.height).fill(0)); // todo IE has no Array.fill
    var degree = params.degree || 1;
    var deg2 = degree / 2;

    var d0 = new Date();

    var v = 0.0, dySq = 0.0;
    var scany = 0;
    var scany2 = 0;
    var dx = 0, rx = 0, base = 0, base2;
    for(var pos in params.data){
        var data = params.data[pos];
        var radius = Math.pow((data / params.step), 1/degree);
        var radiusSq = Math.pow(radius, 2);
        radius = Math.floor(radius);

        var x = Math.floor(pos%params.width);
        var y = Math.floor(pos/params.width);

        // for all circles, lying inside the screen use fast method
        if (y >= radius && y < params.height-radius && x >= radius && x < params.width -1 - radius) {
            fastCalc(params, data, value, x, y, radius, radiusSq);
            continue;
        }

        // calculate point x.y
        var limDx = 0;
        var maxRx = params.width - 1 - x;
        var limRx = x > maxRx ? x : maxRx;

        var maxY = y+radius < params.height - 1 ? y+radius : (params.height - 1);
        for (scany = y - radius; scany < y; scany++) {
            scany2 = (y + y - scany);
            if (scany < 0 && scany2 > maxY)
                continue;

            dySq = Math.pow(scany-y, 2);
            rx = Math.floor(Math.sqrt(radiusSq - dySq));

            base = scany*params.width + x;
            base2 = scany2*params.width + x;

            limDx = rx < maxRx ? -rx : -maxRx;
            for (dx = rx < limRx ? -rx : -limRx; dx < 0; dx++) {

                v = data - params.step * Math.pow(Math.pow(dx, 2) + dySq, deg2);

                if (scany >= 0) {
                    if (x + dx >= 0)
                        value[base + dx] += v;
                    if (dx >= limDx)
                        value[base - dx] += v;
                }
                if (scany2 <= maxY) {
                    if (x + dx >= 0)
                        value[base2 + dx] += v;
                    if (dx >= limDx)
                        value[base2 - dx] += v;
                }
            }
            // dx == 0
            v = data - params.step * Math.pow(dySq, deg2);
            if (scany >= 0)
                value[base] += v;
            if (scany2 <= maxY)
                value[base2] += v;
        }

        // dy == 0 && dx != 0
        base = y*params.width + x;
        limDx = radius < maxRx ? -radius : -maxRx;
        for (dx = radius < limRx ? -radius : -limRx; dx < 0; dx++) {
            v = data - params.step * Math.pow(-dx, degree);   // attention!  power (sqrt(dx^2), degree) == power(dx, degree), but dx < 0 while degree can be float!
            if (x + dx >= 0)
                value[base + dx] += v;
            if (dx >= limDx)
                value[base - dx] += v;
        }

        // dy == dx == 0
        value[base] += data;
    }

    console.log('worker in:', new Date() - d0);
    postMessage({'value': value, 'width': params.width, 'height': params.height});
}

/* Uses fact that circle on the screen has 4 axes of symmetry,
   so it computes values for ~1/8 of points and copies them to symmetrical ones

   Does not check bounds of the image!
   */
function fastCalc(params, data, value, x, y, radius, radiusSq) {
    var base = y * params.width + x;
    var deg2 = params.degree / 2;
    var radiusSq2 = radiusSq / 2;

    var v = 0.0;
    var yOffset = 0, yOffset2 = 0;
    var xOffset = 0;
    var dx = 0, dy = 0, rx = 0.0, dySq = 0;

    for (dy = -radius; dy < 0; dy++) {
        dySq = Math.pow(dy, 2);
        rx = Math.floor(Math.sqrt(radiusSq - dySq));

        yOffset  = base + dy * params.width;
        yOffset2 = base - dy * params.width;

        for (dx = rx >= -dy ? dy + 1 : -rx; dx < 0; dx++) {
            v = data - params.step * Math.pow(Math.pow(dx, 2) + dySq, deg2);
            xOffset = dx * params.width;

            value[yOffset + dx] += v;
            value[yOffset - dx] += v;
            value[yOffset2 + dx] += v;
            value[yOffset2 - dx] += v;

            value[base + xOffset + dy] += v;
            value[base + xOffset - dy] += v;
            value[base - xOffset + dy] += v;
            value[base - xOffset - dy] += v;
        }
        //dy == dx
        if (dySq <= radiusSq2) {
            v = data - params.step * Math.pow(2 * dySq, deg2);
            value[yOffset + dy] += v;
            value[yOffset - dy] += v;
            value[yOffset2 + dy] += v;
            value[yOffset2 - dy] += v;
        }

        // dx = 0
        v = data - params.step * Math.pow(dySq, deg2);
        value[yOffset] += v;
        value[yOffset2] += v;
        value[base + dy] += v;
        value[base - dy] += v;
    }

    // dx == dy == 0
    value[base] += data;
}
