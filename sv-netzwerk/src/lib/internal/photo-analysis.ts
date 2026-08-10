/**
 * Photo Analysis Engine
 * Analyzes room and window photos to extract:
 * - Room numbers from room signs (OCR)
 * - Window geometry: number of sashes/flügel, swing direction (left/right)
 * Uses local TensorFlow.js with fallback to cloud API if needed
 */

export interface PhotoAnalysisResult {
  type: 'room-sign' | 'window';
  roomNumber?: string | null;
  roomNumberConfidence?: number;
  windowFluegelCount?: number; // 1, 2, 3+
  windowSwingDirection?: 'left' | 'right' | 'center'; // Griff position opposite to hinge
  swingDirectionConfidence?: number;
  error?: string;
  rawAnalysis?: Record<string, any>;
}

export interface PhotoAnalysisOptions {
  type: 'room-sign' | 'window';
  image: Blob | File | string; // base64 or blob
  projectId: string;
  useCloudAPI?: boolean;
}

let tensorflowLoaded = false;
let tf: any = null;
let cocoSsd: any = null;

/**
 * Initialize TensorFlow.js and COCO-SSD for local object detection
 */
async function initializeTensorFlow(): Promise<void> {
  if (tensorflowLoaded) return;

  try {
    // Load TensorFlow.js
    const tfScript = document.createElement('script');
    tfScript.src = 'https://cdn.jsdelivr.net/npm/@tensorflow/tfjs@4.0.0';
    await new Promise((resolve) => {
      tfScript.onload = resolve;
      document.head.appendChild(tfScript);
    });

    // Load COCO-SSD for object detection
    const cocoScript = document.createElement('script');
    cocoScript.src = 'https://cdn.jsdelivr.net/npm/@tensorflow-models/coco-ssd@2.2.3';
    await new Promise((resolve) => {
      cocoScript.onload = resolve;
      document.head.appendChild(cocoScript);
    });

    tf = (window as any).tf;
    cocoSsd = (window as any).cocoSsd;
    tensorflowLoaded = true;
  } catch (err) {
    console.warn('TensorFlow.js initialization failed, will use cloud API:', err);
    tensorflowLoaded = false;
  }
}

/**
 * Detect window geometry from photo (flügel count, swing direction)
 */
async function analyzeWindowPhoto(image: HTMLImageElement): Promise<Partial<PhotoAnalysisResult>> {
  if (!cocoSsd || !tf) {
    return { error: 'TensorFlow not available' };
  }

  try {
    const model = await cocoSsd.load();
    const predictions = await model.estimateObjects(image);

    // Look for window-related objects
    const windowObjects = predictions.filter(
      (p: any) =>
        p.class === 'window' ||
        p.class === 'door' ||
        p.score > 0.5
    );

    if (windowObjects.length === 0) {
      return { error: 'No window detected in photo' };
    }

    // Analyze window geometry
    const result = analyzeWindowGeometry(windowObjects, image.width, image.height);
    return result;
  } catch (err) {
    console.error('Window analysis failed:', err);
    return { error: String(err) };
  }
}

/**
 * Analyze window geometry to determine flügel count and swing direction
 */
function analyzeWindowGeometry(
  objects: any[],
  imageWidth: number,
  imageHeight: number
): Partial<PhotoAnalysisResult> {
  // Find the largest window-like object
  const window = objects.reduce((max, obj) =>
    (obj.bbox[2] * obj.bbox[3] > max.bbox[2] * max.bbox[3] ? obj : max)
  );

  if (!window) {
    return { error: 'Could not identify main window' };
  }

  const [x, y, w, h] = window.bbox;

  // Detect vertical divisions (sashes/flügel)
  const fluegelCount = detectFluegelCount(w, h);

  // Detect swing direction based on handle position heuristics
  const swingDirection = detectSwingDirection(x, y, w, h, imageWidth, imageHeight);

  return {
    windowFluegelCount: fluegelCount,
    swingDirection,
    swingDirectionConfidence: 0.6, // Conservative estimate
    rawAnalysis: { window, imageWidth, imageHeight },
  };
}

/**
 * Heuristic: Detect number of window sashes/flügel from width
 * Typical window aspect ratios: 1-flügel ~1:1.2, 2-flügel ~2:1.1, 3-flügel ~3:1
 */
function detectFluegelCount(width: number, height: number): number {
  const ratio = width / height;

  if (ratio > 2.5) return 3;
  if (ratio > 1.7) return 2;
  return 1;
}

/**
 * Heuristic: Detect swing direction (left/right)
 * Griff (handle) is always opposite to Anschlag (hinge)
 * We estimate based on likely window positioning
 */
function detectSwingDirection(
  x: number,
  y: number,
  w: number,
  h: number,
  imageWidth: number,
  imageHeight: number
): 'left' | 'right' | 'center' {
  // Horizontal center position of window in image
  const windowCenter = x + w / 2;
  const imageCenterX = imageWidth / 2;

  // If window is left-biased, hinge likely on left, handle/grip on right
  // If window is right-biased, hinge likely on right, handle/grip on left
  if (windowCenter < imageCenterX * 0.75) {
    return 'right'; // Griff rechts
  }
  if (windowCenter > imageCenterX * 1.25) {
    return 'left'; // Griff links
  }
  return 'center';
}

/**
 * Perform OCR on room sign photo to extract room number
 * Uses Tesseract.js for browser-based OCR
 */
async function analyzeRoomSignPhoto(image: HTMLImageElement): Promise<Partial<PhotoAnalysisResult>> {
  try {
    // Load Tesseract.js for OCR
    if (!(window as any).Tesseract) {
      const script = document.createElement('script');
      script.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
      await new Promise((resolve) => {
        script.onload = resolve;
        document.head.appendChild(script);
      });
    }

    const Tesseract = (window as any).Tesseract;
    const { data } = await Tesseract.recognize(image, 'deu', {
      logger: (m: any) => console.log('OCR progress:', m.progress),
    });

    // Extract room number from OCR text
    const roomNumber = extractRoomNumber(data.text);

    return {
      roomNumber,
      roomNumberConfidence: data.confidence ? data.confidence / 100 : 0.7,
      rawAnalysis: { ocrText: data.text },
    };
  } catch (err) {
    console.error('Room sign OCR failed:', err);
    return { error: String(err) };
  }
}

/**
 * Extract room number from OCR text using regex patterns
 * Matches patterns like: "Raum 42", "Room 101", "Zimmer 7", or just numbers
 */
function extractRoomNumber(text: string): string | null {
  // Common patterns
  const patterns = [
    /(?:Raum|Room|Zimmer|Z\.?)\s*[:\.]?\s*(\d+[a-zA-Z]?)/i,
    /Nr\.?\s*(\d+[a-zA-Z]?)/i,
    /^\s*(\d+[a-zA-Z]?)\s*$/m, // Just a number
    /(\d+[a-zA-Z]?)\s*$/m, // Number at end of line
  ];

  for (const pattern of patterns) {
    const match = text.match(pattern);
    if (match && match[1]) {
      return match[1].trim();
    }
  }

  return null;
}

/**
 * Main photo analysis function
 * Automatically chooses between local TensorFlow and cloud API
 */
export async function analyzePhoto(options: PhotoAnalysisOptions): Promise<PhotoAnalysisResult> {
  try {
    // Convert image to HTMLImageElement if needed
    let image: HTMLImageElement;

    if (typeof options.image === 'string') {
      // Base64 string
      image = new Image();
      image.src = options.image;
      await new Promise((resolve) => {
        image.onload = resolve;
      });
    } else if (options.image instanceof Blob || options.image instanceof File) {
      // Blob/File
      const url = URL.createObjectURL(options.image);
      image = new Image();
      image.src = url;
      await new Promise((resolve) => {
        image.onload = resolve;
      });
    } else {
      throw new Error('Invalid image format');
    }

    // Try local analysis first (unless cloud API explicitly requested)
    if (!options.useCloudAPI) {
      try {
        await initializeTensorFlow();

        if (options.type === 'room-sign') {
          const result = await analyzeRoomSignPhoto(image);
          if (!result.error) {
            return { type: 'room-sign', ...result } as PhotoAnalysisResult;
          }
        } else if (options.type === 'window') {
          const result = await analyzeWindowPhoto(image);
          if (!result.error) {
            return { type: 'window', ...result } as PhotoAnalysisResult;
          }
        }
      } catch (err) {
        console.warn('Local analysis failed, would need cloud fallback:', err);
      }
    }

    // Fallback: Use cloud API (Google Cloud Vision or similar)
    return await analyzePhotoWithCloudAPI(options);
  } catch (err) {
    return {
      type: options.type,
      error: `Photo analysis failed: ${err}`,
    };
  }
}

/**
 * Cloud API fallback using Google Cloud Vision
 * Requires GOOGLE_CLOUD_VISION_API_KEY environment variable
 */
async function analyzePhotoWithCloudAPI(options: PhotoAnalysisOptions): Promise<PhotoAnalysisResult> {
  try {
    const apiKey = (import.meta.env as any).PUBLIC_GOOGLE_VISION_API_KEY;

    if (!apiKey) {
      return {
        type: options.type,
        error: 'Cloud Vision API key not configured. Please configure PUBLIC_GOOGLE_VISION_API_KEY',
      };
    }

    // Convert image to base64
    let base64Image: string;

    if (typeof options.image === 'string') {
      base64Image = options.image.split(',')[1] || options.image;
    } else {
      const reader = new FileReader();
      base64Image = await new Promise((resolve, reject) => {
        reader.onload = () => {
          const result = reader.result as string;
          resolve(result.split(',')[1]);
        };
        reader.onerror = reject;
        reader.readAsDataURL(options.image as Blob);
      });
    }

    const endpoint = 'https://vision.googleapis.com/v1/images:annotate?key=' + apiKey;

    // Prepare request body
    const features =
      options.type === 'room-sign'
        ? [{ type: 'TEXT_DETECTION' }]
        : [
            { type: 'OBJECT_LOCALIZATION' },
            { type: 'IMAGE_PROPERTIES' },
          ];

    const response = await fetch(endpoint, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        requests: [
          {
            image: { content: base64Image },
            features: features,
          },
        ],
      }),
    });

    if (!response.ok) {
      throw new Error(`Google Vision API error: ${response.statusText}`);
    }

    const result = await response.json();
    const responses = result.responses?.[0];

    if (options.type === 'room-sign') {
      const textAnnotations = responses.textAnnotations || [];
      if (textAnnotations.length > 0) {
        const fullText = textAnnotations[0].description || '';
        const roomNumber = extractRoomNumber(fullText);
        return {
          type: 'room-sign',
          roomNumber,
          roomNumberConfidence: 0.8,
          rawAnalysis: { fullText },
        };
      }
    } else if (options.type === 'window') {
      // Analyze object localization for windows
      const localizedObjects = responses.localizedObjectAnnotations || [];
      const windowObjects = localizedObjects.filter((obj: any) =>
        ['window', 'door', 'frame'].some((t) =>
          obj.name?.toLowerCase().includes(t)
        )
      );

      if (windowObjects.length > 0) {
        // Use first/largest window
        const mainWindow = windowObjects[0];
        const vertices = mainWindow.boundingPoly?.normalizedVertices || [];

        // Estimate flügel count and direction from bounding box
        const result = analyzeWindowGeometry(
          windowObjects.map((w: any) => ({
            bbox: [
              w.boundingPoly?.normalizedVertices[0]?.x || 0,
              w.boundingPoly?.normalizedVertices[0]?.y || 0,
              Math.abs((w.boundingPoly?.normalizedVertices[2]?.x || 1) - (w.boundingPoly?.normalizedVertices[0]?.x || 0)),
              Math.abs((w.boundingPoly?.normalizedVertices[2]?.y || 1) - (w.boundingPoly?.normalizedVertices[0]?.y || 0)),
            ],
            name: w.name,
            score: w.score,
          })),
          1,
          1
        );

        return {
          type: 'window',
          ...result,
          swingDirectionConfidence: 0.65,
          rawAnalysis: { windowObjects },
        };
      }
    }

    return {
      type: options.type,
      error: 'No relevant objects detected in image',
    };
  } catch (err) {
    return {
      type: options.type,
      error: `Cloud Vision API error: ${err}`,
    };
  }
}

/**
 * Prefill window form based on photo analysis results
 */
export function prefillFormFromAnalysis(
  analysis: PhotoAnalysisResult,
  workingCopy: Record<string, any>,
  formElement: HTMLFormElement
): void {
  if (analysis.type === 'room-sign' && analysis.roomNumber) {
    // Prefill room number field
    const roomField = formElement.querySelector<HTMLInputElement>('[name="room_number"]');
    if (roomField) {
      roomField.value = analysis.roomNumber;
      roomField.dispatchEvent(new Event('input', { bubbles: true }));
      workingCopy.room_number = analysis.roomNumber;
    }
  }

  if (analysis.type === 'window') {
    if (analysis.windowFluegelCount) {
      // Map flügel count to opening_type
      const openingTypeMap: Record<number, string> = {
        1: 'Einfachfenster (ein Flügel)',
        2: 'Drehkippfenster (zwei Flügel)',
        3: 'Drehkippfenster (drei Flügel)',
      };

      const openingType = openingTypeMap[analysis.windowFluegelCount];
      if (openingType) {
        const typeField = formElement.querySelector<HTMLSelectElement>('[name="opening_type"]');
        if (typeField) {
          typeField.value = openingType;
          typeField.dispatchEvent(new Event('change', { bubbles: true }));
          workingCopy.opening_type = openingType;
        }
      }
    }

    if (analysis.windowSwingDirection && analysis.windowSwingDirection !== 'center') {
      // Store swing direction for reference (can be used in extended form)
      const hingeField = formElement.querySelector<HTMLSelectElement>('[name="hinge_system"]');
      if (hingeField && hingeField.options) {
        // Look for direction-specific hinge options
        const direction = analysis.windowSwingDirection === 'left' ? 'Links' : 'Rechts';
        for (let i = 0; i < hingeField.options.length; i++) {
          const option = hingeField.options[i];
          if (option.text.includes(direction)) {
            hingeField.value = option.value;
            hingeField.dispatchEvent(new Event('change', { bubbles: true }));
            workingCopy.hinge_system = option.value;
            break;
          }
        }
      }
    }
  }
}
