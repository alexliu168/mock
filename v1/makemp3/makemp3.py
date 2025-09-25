#!/usr/bin/env python3
import base64
import json
import os
from pathlib import Path

from tencentcloud.common import credential
from tencentcloud.common.exception.tencent_cloud_sdk_exception import TencentCloudSDKException
from tencentcloud.tts.v20190823 import tts_client, models


# Read API credentials from environment variables
SECRET_ID = os.getenv("TENCENT_SECRET_ID")
SECRET_KEY = os.getenv("TENCENT_SECRET_KEY")
REGION = "ap-hongkong"


def text_to_mp3(text: str, out_path: Path, secret_id: str, secret_key: str, region="ap-hongkong"):
    try:
        cred = credential.Credential(secret_id, secret_key)
        client = tts_client.TtsClient(cred, region)

        req = models.TextToVoiceRequest()
        params = {
            "Text": text,
            "SessionId": "tts_batch",
            "ModelType": 1,        # Standard voice
            "VoiceType": 101016,   # Female voice (adjust if needed)
            "Codec": "mp3"
        }
        req.from_json_string(json.dumps(params))

        resp = client.TextToVoice(req)
        audio_b64 = resp.Audio
        audio_bytes = base64.b64decode(audio_b64)

        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_bytes(audio_bytes)
        print(f"✅ MP3 saved: {out_path}")

    except TencentCloudSDKException as e:
        print("❌ TencentCloudSDKException:", e)


def main():
    if not SECRET_ID or not SECRET_KEY:
        raise EnvironmentError("❌ Please set TENCENT_SECRET_ID and TENCENT_SECRET_KEY environment variables.")

    courses_file = Path("courses.json")
    courses = json.loads(courses_file.read_text(encoding="utf-8"))

    for course in courses:
        text = course["en"].strip()
        out_path = Path(course["audio"])
        text_to_mp3(text, out_path, SECRET_ID, SECRET_KEY, REGION)


if __name__ == "__main__":
    main()
