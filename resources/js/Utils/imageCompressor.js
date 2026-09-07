/**
 * Image compressor & resizer utility for client-side uploads.
 * - Resizes images to standard 1080x1350 px (Instagram/Facebook 4:5 portrait)
 * - Auto-renames files with datetime (YYYY-MM-DD_HH-mm-ss.jpg)
 * - Compresses to ensure files remain well under the 2 MB limit
 */

const DEFAULT_MAX_BYTES = 2 * 1024 * 1024; // 2 MB
const DEFAULT_TARGET_BYTES = 1.75 * 1024 * 1024; // 1.75 MB safe ceiling
const DEFAULT_MAX_DIMENSION = 2560; // Max 2.5K width/height

/**
 * Generate a clean, URL-safe datetime filename: YYYY-MM-DD_HH-mm-ss.ext
 *
 * @param {Date} [date=new Date()]
 * @param {number} [index=0]
 * @param {string} [extension='jpg']
 * @returns {string}
 */
export function formatDateTimeFilename(date = new Date(), index = 0, extension = 'jpg') {
    const pad = (n) => String(n).padStart(2, '0');
    const y = date.getFullYear();
    const m = pad(date.getMonth() + 1);
    const d = pad(date.getDate());
    const hh = pad(date.getHours());
    const mm = pad(date.getMinutes());
    const ss = pad(date.getSeconds());

    const suffix = index > 0 ? `_${index + 1}` : '';
    const cleanExt = (extension || 'jpg').replace(/^\./, '').toLowerCase();

    return `${y}-${m}-${d}_${hh}-${mm}-${ss}${suffix}.${cleanExt}`;
}

/**
 * Check if the browser supports exporting canvas to a specific MIME type.
 */
function isMimeTypeSupported(mimeType) {
    if (typeof document === 'undefined') return false;
    const canvas = document.createElement('canvas');
    canvas.width = 1;
    canvas.height = 1;
    try {
        const dataUrl = canvas.toDataURL(mimeType);
        return dataUrl.startsWith(`data:${mimeType}`);
    } catch {
        return false;
    }
}

/**
 * Convert a canvas to a Blob via Promise.
 */
function canvasToBlob(canvas, mimeType, quality) {
    return new Promise((resolve) => {
        canvas.toBlob(
            (blob) => resolve(blob),
            mimeType,
            quality,
        );
    });
}

/**
 * Load a File into an HTMLImageElement.
 */
function loadImageFromFile(file) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);

        img.onload = () => {
            URL.revokeObjectURL(url);
            resolve(img);
        };

        img.onerror = (err) => {
            URL.revokeObjectURL(url);
            reject(err);
        };

        img.src = url;
    });
}

/**
 * Calculate scaled dimensions while preserving aspect ratio.
 */
function calculateDimensions(width, height, maxDimension) {
    let newWidth = width;
    let newHeight = height;

    if (newWidth > maxDimension || newHeight > maxDimension) {
        if (newWidth >= newHeight) {
            newHeight = Math.round((newHeight * maxDimension) / newWidth);
            newWidth = maxDimension;
        } else {
            newWidth = Math.round((newWidth * maxDimension) / newHeight);
            newHeight = maxDimension;
        }
    }

    return { width: Math.max(1, newWidth), height: Math.max(1, newHeight) };
}

/**
 * Resize image to exact target dimensions (default 1080x1350 px)
 * and rename with datetime.
 *
 * @param {File} file
 * @param {Object} [options]
 * @param {number} [options.targetWidth=1080]
 * @param {number} [options.targetHeight=1350]
 * @param {number} [options.maxBytes=2097152]
 * @param {boolean} [options.renameWithDateTime=true]
 * @param {number} [options.index=0]
 * @param {string} [options.mimeType='image/jpeg']
 * @param {number} [options.quality=0.90]
 * @returns {Promise<{ file: File, changed: boolean, originalSize: number, finalSize: number, originalName: string, newName: string }>}
 */
export async function resizeImageToTarget(file, options = {}) {
    const targetWidth = options.targetWidth ?? 1080;
    const targetHeight = options.targetHeight ?? 1350;
    const maxBytes = options.maxBytes ?? DEFAULT_MAX_BYTES;
    const renameWithDateTime = options.renameWithDateTime ?? true;
    const index = options.index ?? 0;
    const mimeType = options.mimeType ?? 'image/jpeg';
    const quality = options.quality ?? 0.90;

    if (!file || !(file instanceof File)) {
        return {
            file,
            changed: false,
            originalSize: file?.size || 0,
            finalSize: file?.size || 0,
            originalName: file?.name || '',
            newName: file?.name || '',
        };
    }

    const type = String(file.type || '').toLowerCase();
    const isPng = type === 'image/png' || file.name.toLowerCase().endsWith('.png');
    const isJpg = type === 'image/jpeg' || file.name.toLowerCase().match(/\.(jpe?g)$/i);
    const isWebp = type === 'image/webp' || file.name.toLowerCase().endsWith('.webp');

    // If not a standard raster image (e.g. SVG or animated GIF)
    if (!isPng && !isJpg && !isWebp) {
        if (renameWithDateTime) {
            const ext = file.name.split('.').pop() || 'file';
            const newName = formatDateTimeFilename(new Date(), index, ext);
            const renamedFile = new File([file], newName, {
                type: file.type,
                lastModified: Date.now(),
            });
            return {
                file: renamedFile,
                changed: true,
                originalSize: file.size,
                finalSize: renamedFile.size,
                originalName: file.name,
                newName: newName,
            };
        }
        return {
            file,
            changed: false,
            originalSize: file.size,
            finalSize: file.size,
            originalName: file.name,
            newName: file.name,
        };
    }

    try {
        const img = await loadImageFromFile(file);

        const canvas = document.createElement('canvas');
        canvas.width = targetWidth;
        canvas.height = targetHeight;

        const ctx = canvas.getContext('2d');
        if (!ctx) {
            throw new Error('Canvas 2D context not available');
        }

        // Fill background with white (prevents black background on transparent PNGs)
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, targetWidth, targetHeight);

        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';

        // Deterministic cover: scale image to fill 1080x1350 preserving aspect ratio
        const scale = Math.max(targetWidth / img.width, targetHeight / img.height);
        const nw = Math.round(img.width * scale);
        const nh = Math.round(img.height * scale);
        const x = Math.round((targetWidth - nw) / 2);
        const y = Math.round((targetHeight - nh) / 2);

        ctx.drawImage(img, x, y, nw, nh);

        // Quality passes to ensure result fits comfortably under maxBytes (2MB)
        let currentQuality = quality;
        let blob = await canvasToBlob(canvas, mimeType, currentQuality);

        const qualitySteps = [0.85, 0.78, 0.70];
        let stepIdx = 0;
        while (blob && blob.size > maxBytes && stepIdx < qualitySteps.length) {
            currentQuality = qualitySteps[stepIdx++];
            blob = await canvasToBlob(canvas, mimeType, currentQuality);
        }

        if (!blob) {
            throw new Error('Failed to create image blob');
        }

        const ext = mimeType === 'image/jpeg' ? 'jpg' : mimeType === 'image/webp' ? 'webp' : 'png';
        const newName = renameWithDateTime
            ? formatDateTimeFilename(new Date(), index, ext)
            : file.name.replace(/\.[^.]+$/, `.${ext}`);

        const resizedFile = new File([blob], newName, {
            type: mimeType,
            lastModified: Date.now(),
        });

        return {
            file: resizedFile,
            changed: true,
            originalSize: file.size,
            finalSize: resizedFile.size,
            originalName: file.name,
            newName: newName,
            width: targetWidth,
            height: targetHeight,
        };
    } catch (err) {
        console.warn('Image resize/rename failed, falling back:', err);
        return {
            file,
            changed: false,
            originalSize: file.size,
            finalSize: file.size,
            originalName: file.name,
            newName: file.name,
        };
    }
}

/**
 * Compress an image File if it exceeds maxBytes (used for logos/general images).
 *
 * @param {File} file
 * @param {Object} [options]
 * @param {number} [options.maxBytes=2097152]
 * @param {number} [options.targetBytes=1835008]
 * @param {number} [options.maxDimension=2560]
 * @returns {Promise<{ file: File, compressed: boolean, originalSize: number, finalSize: number }>}
 */
export async function compressImageIfNeeded(file, options = {}) {
    const maxBytes = options.maxBytes ?? DEFAULT_MAX_BYTES;
    const targetBytes = options.targetBytes ?? DEFAULT_TARGET_BYTES;
    const maxDimension = options.maxDimension ?? DEFAULT_MAX_DIMENSION;

    if (!file || !(file instanceof File)) {
        return { file, compressed: false, originalSize: file?.size || 0, finalSize: file?.size || 0 };
    }

    if (file.size <= maxBytes) {
        return { file, compressed: false, originalSize: file.size, finalSize: file.size };
    }

    const type = String(file.type || '').toLowerCase();
    const isPng = type === 'image/png' || file.name.toLowerCase().endsWith('.png');
    const isJpg = type === 'image/jpeg' || file.name.toLowerCase().match(/\.(jpe?g)$/i);
    const isWebp = type === 'image/webp' || file.name.toLowerCase().endsWith('.webp');

    if (!isPng && !isJpg && !isWebp) {
        return { file, compressed: false, originalSize: file.size, finalSize: file.size };
    }

    try {
        const img = await loadImageFromFile(file);
        const supportsWebp = isMimeTypeSupported('image/webp');
        const outputMime = supportsWebp ? 'image/webp' : isPng ? 'image/png' : 'image/jpeg';

        let currentMaxDim = maxDimension;
        let bestBlob = null;

        const passes = [
            { maxDim: currentMaxDim, q: 0.85 },
            { maxDim: currentMaxDim, q: 0.75 },
            { maxDim: Math.min(currentMaxDim, 2048), q: 0.70 },
            { maxDim: Math.min(currentMaxDim, 1600), q: 0.60 },
        ];

        for (const pass of passes) {
            const { width, height } = calculateDimensions(img.width, img.height, pass.maxDim);

            const canvas = document.createElement('canvas');
            canvas.width = width;
            canvas.height = height;

            const ctx = canvas.getContext('2d', { alpha: outputMime !== 'image/jpeg' });

            if (outputMime === 'image/jpeg') {
                ctx.fillStyle = '#FFFFFF';
                ctx.fillRect(0, 0, width, height);
            }

            ctx.drawImage(img, 0, 0, width, height);

            const blob = await canvasToBlob(canvas, outputMime, pass.q);

            if (blob) {
                bestBlob = blob;
                if (blob.size <= targetBytes) {
                    break;
                }
            }
        }

        if (!bestBlob || bestBlob.size >= file.size) {
            return { file, compressed: false, originalSize: file.size, finalSize: file.size };
        }

        let newName = file.name;
        if (bestBlob.type === 'image/webp' && !newName.toLowerCase().endsWith('.webp')) {
            newName = newName.replace(/\.[^.]+$/, '') + '.webp';
        } else if (bestBlob.type === 'image/jpeg' && !newName.toLowerCase().match(/\.(jpe?g)$/i)) {
            newName = newName.replace(/\.[^.]+$/, '') + '.jpg';
        }

        const compressedFile = new File([bestBlob], newName, {
            type: bestBlob.type,
            lastModified: Date.now(),
        });

        return {
            file: compressedFile,
            compressed: true,
            originalSize: file.size,
            finalSize: compressedFile.size,
        };
    } catch (err) {
        console.warn('Image compression failed, falling back to original file:', err);
        return { file, compressed: false, originalSize: file.size, finalSize: file.size };
    }
}
