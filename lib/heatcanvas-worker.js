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

    var setVal = function(pos, v) {
        if (value[pos])
            value[pos] += v;
        else
            value[pos] = v;
    };

    for(var pos in params.data){
        var data = params.data[pos];
        var radius = Math.pow((data / params.step), 1/degree);
        var radiusSq = Math.pow(radius, 2);
        radius = Math.floor(radius);

        var x = Math.floor(pos%params.width);
        var y = Math.floor(pos/params.width);

        // for all circles, lying inside the screen use fast maethod
        if (y >= radius && y < params.height-radius && x >= radius && x < params.width -1 - radius) {
            fastCalc(params, data, value, x, y, radius, radiusSq);
            continue;
        }

        // calculate point x.y
        var maxY = y+radius < params.height - 1 ? y+radius : (params.height - 1);
        for (var scany = y - radius; scany <= maxY; scany++) {
            if (scany < 0)
                continue;

            var dy2 = Math.pow(scany-y, 2);
            var rx = Math.floor(Math.sqrt(radiusSq - dy2));

            var base = scany*params.width + x;
            var xLim = x - (x + rx < params.width-1 ? x + rx : (params.width -1));
            for (var dx = -rx; dx < 0; dx++) {

                var v = data - params.step * Math.pow(Math.pow(dx, 2) + dy2, deg2);

                if (x + dx >= 0)
                    setVal(base + dx, v);
                if (dx >= xLim)
                    setVal(base - dx, v);
            }
            // dx = 0
            var v0 = data - params.step * Math.pow(dy2, deg2);
            setVal(base, v0);
        }
    }

    console.log('worker', 'in: ', new Date() - d0);
    postMessage({'value': value, 'width': params.width, 'height': params.height});
}

/* uses fact that circle on the screen has 4 axes of symmetry,
   so it computes values for ~1/8 of points and copies them to symmetrical ones
   */
function fastCalc(params, data, value, x, y, radius, radiusSq) {
    var setVal = function(pos, v) {
        if (value[pos])
            value[pos] += v;
        else
            value[pos] = v;
    };

    var base = y * params.width + x;
    var deg2 = params.degree / 2;
    var radiusSq2 = radiusSq / 2;

    for (var dy = -radius; dy < 0; dy++) {
        var dy2 = Math.pow(dy, 2);
        var rx = Math.floor(Math.sqrt(radiusSq - dy2));

        var yOffset  = base + dy * params.width;
        var yOffset2 = base - dy * params.width;

        for (var dx = rx >= -dy ? dy + 1 : -rx; dx < 0; dx++) {
            var v = data - params.step * Math.pow(Math.pow(dx, 2) + dy2, deg2);
            var xOffset = dx * params.width;

            setVal(yOffset + dx, v);
            setVal(yOffset - dx, v);
            setVal(yOffset2 + dx, v);
            setVal(yOffset2 - dx, v);

            setVal(base + xOffset + dy, v);
            setVal(base + xOffset - dy, v);
            setVal(base - xOffset + dy, v);
            setVal(base - xOffset - dy, v);
        }
        //dy == dx
        var v0;
        if (dy2 <= radiusSq2) {
            v0 = data - params.step * Math.pow(2 * dy2, deg2);
            setVal(yOffset + dy, v0);
            setVal(yOffset - dy, v0);
            setVal(yOffset2 + dy, v0);
            setVal(yOffset2 - dy, v0);
        }

        // dx = 0
        v0 = data - params.step * Math.pow(dy2, deg2);
        setVal(yOffset, v0);
        setVal(yOffset2, v0);
        setVal(base + dy, v0);
        setVal(base - dy, v0);
    }

    // dx == dy == 0
    setVal(base, data);
}
