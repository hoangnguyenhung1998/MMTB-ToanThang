from pathlib import Path
from google_auth_oauthlib.flow import InstalledAppFlow

SCOPES=["https://www.googleapis.com/auth/gmail.readonly"]

def authorize(root: Path) -> None:
    credentials=root / "credentials.json"
    if not credentials.exists(): raise FileNotFoundError(f"Missing {credentials}")
    flow=InstalledAppFlow.from_client_secrets_file(str(credentials),SCOPES)
    token=flow.run_local_server(port=0,access_type="offline",prompt="consent")
    (root / "token.json").write_text(token.to_json(),encoding="utf-8")
    print("Gmail authorization completed. token.json was created.")

def main() -> None:
    authorize(Path(__file__).resolve().parents[2])

if __name__ == "__main__":
    main()
