import axios from 'axios';
import crypto from 'crypto';
import { S3Client, GetObjectCommand } from '@aws-sdk/client-s3';
import { Readable } from 'stream';

// API endpoint to send the playlist and thumbnail URLs
const API_ENDPOINT = "https://memesplanet.com/api/lambda/video";

// Initialize the S3 client
const s3 = new S3Client();

/**
 * Generate MD5 hash for a given string
 * @param {string} data - The data to hash
 * @returns {string} - The MD5 hash
 */
const generateMD5Hash = (data) => {
    return crypto.createHash('md5').update(data).digest('hex');
};

/**
 * Fetch the playlist file from S3 and calculate its size and total duration
 * @param {string} bucketName - The S3 bucket name
 * @param {string} objectKey - The object key of the playlist file
 * @returns {Promise<{ size: number, duration: number }>}
 */
const getPlaylistInfo = async (bucketName, objectKey) => {
    try {
        const command = new GetObjectCommand({ Bucket: bucketName, Key: objectKey });
        const response = await s3.send(command);

        const chunks = [];
        const readable = Readable.from(response.Body);

        for await (const chunk of readable) {
            chunks.push(chunk);
        }

        const playlistContent = Buffer.concat(chunks).toString('utf-8');
        const size = Buffer.byteLength(playlistContent, 'utf-8');

        // Calculate total duration from the playlist
        const duration = playlistContent
            .split('\n')
            .filter((line) => line.startsWith('#EXTINF:'))
            .map((line) => parseFloat(line.replace('#EXTINF:', '').split(',')[0]))
            .reduce((total, current) => total + current, 0);

        return { size, duration };
    } catch (error) {
        console.error("Error fetching playlist:", error);
        throw error;
    }
};

/**
 * AWS Lambda handler
 * @param {Object} event - The S3 event payload
 */
export const handler = async (event) => {
    try {
        const records = event.Records || [];

        // Iterate over S3 event records
        for (const record of records) {
            const bucketName = record.s3.bucket.name;
            const objectKey = decodeURIComponent(record.s3.object.key.replace(/\+/g, ' '));

            // Check if the uploaded file is a .m3u8 playlist
            if (objectKey.endsWith('.m3u8')) {
                const playlistUrl = `https://${bucketName}.s3.amazonaws.com/${objectKey}`;
                const basePath = objectKey.substring(0, objectKey.lastIndexOf('/'));
                const fileName = objectKey.substring(objectKey.lastIndexOf('/') + 1);
                const thumbnailPath = `${basePath}/thumbnail.jpg`;
                const filePath = objectKey;

                // Fetch playlist size and duration
                const { size, duration } = await getPlaylistInfo(bucketName, objectKey);

                // Generate MD5 hash for the playlist URL
                const md5Hash = generateMD5Hash(playlistUrl);

                // Prepare payload for API
                const payload = {
                    file_name: "",
                    file_path: filePath,
                    thumbnail_path: thumbnailPath,
                    file_hash: md5Hash,
                    file_size, // in bytes
                    duration, // in seconds
                    mime_type: 'application/x-mpegURL'
                };

                // Send payload to API
                const response = await axios.post(API_ENDPOINT, payload, {
                    headers: {
                        "X-Lambda-Token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJNZW1lc1BsYW5ldCIsImlhdCI6MTczNTY3NDQ4MCwiZXhwIjoxOTI0OTc2ODgwLCJhdWQiOiJ3d3cubWVtZXNwbGFuZXQuY29tIiwic3ViIjoiYWRtaW5AbWVtZXNwbGFuZXQuY29tIn0.q-Lud_GI20XOf7dWGCvIDSPP4z7g2SwFyF5Mntf7iO4"
                    }
                });

                if (response.status === 200) {
                    console.log(`Successfully sent data to API: ${JSON.stringify(payload)}`);
                } else {
                    console.error(
                        `Failed to send data to API. Status Code: ${response.status}, Response: ${response.data}`
                    );
                }
            }
        }
    } catch (error) {
        console.error("Error processing S3 event:", error);
        throw error;
    }
};
