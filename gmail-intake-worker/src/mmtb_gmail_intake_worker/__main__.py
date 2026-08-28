import logging,time
from pathlib import Path
from .config import Settings
from .process_lock import ProcessLock
from .worker import Worker

def main()->None:
    root=Path(__file__).resolve().parents[2]; data=root/"data"; data.mkdir(exist_ok=True)
    logging.basicConfig(level=logging.INFO,format="%(asctime)s %(levelname)s %(message)s",handlers=[logging.FileHandler(data/"worker.log",encoding="utf-8"),logging.StreamHandler()])
    settings=Settings.load(root)
    with ProcessLock(data/"worker.lock"):
        worker=Worker(settings); logging.info("MMTB Gmail intake worker started")
        while True:
            try: worker.step(); time.sleep(settings.poll_seconds)
            except KeyboardInterrupt: return
            except Exception: logging.exception("Gmail intake loop failed; retrying"); time.sleep(max(30,settings.poll_seconds))

if __name__=="__main__": main()
