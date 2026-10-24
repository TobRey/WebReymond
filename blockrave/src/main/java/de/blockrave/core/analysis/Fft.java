package de.blockrave.core.analysis;

/** Einfache iterative Radix-2-FFT (in-place) fuer die Analyse. */
final class Fft {
    private final int n;
    private final int[] rev;
    private final double[] cos;
    private final double[] sin;

    Fft(int n) {
        if (Integer.bitCount(n) != 1) throw new IllegalArgumentException("n muss Zweierpotenz sein");
        this.n = n;
        int bits = Integer.numberOfTrailingZeros(n);
        rev = new int[n];
        for (int i = 0; i < n; i++) rev[i] = Integer.reverse(i) >>> (32 - bits);
        cos = new double[n / 2];
        sin = new double[n / 2];
        for (int i = 0; i < n / 2; i++) {
            cos[i] = Math.cos(-2 * Math.PI * i / n);
            sin[i] = Math.sin(-2 * Math.PI * i / n);
        }
    }

    void transform(double[] re, double[] im) {
        for (int i = 0; i < n; i++) {
            int j = rev[i];
            if (j > i) {
                double t = re[i]; re[i] = re[j]; re[j] = t;
                t = im[i]; im[i] = im[j]; im[j] = t;
            }
        }
        for (int size = 2; size <= n; size <<= 1) {
            int half = size >> 1;
            int step = n / size;
            for (int start = 0; start < n; start += size) {
                for (int k = 0; k < half; k++) {
                    double wr = cos[k * step];
                    double wi = sin[k * step];
                    int a = start + k;
                    int b = a + half;
                    double xr = re[b] * wr - im[b] * wi;
                    double xi = re[b] * wi + im[b] * wr;
                    re[b] = re[a] - xr;
                    im[b] = im[a] - xi;
                    re[a] += xr;
                    im[a] += xi;
                }
            }
        }
    }
}
