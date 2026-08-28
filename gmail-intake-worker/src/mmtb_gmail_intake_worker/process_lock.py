from pathlib import Path
import os

class ProcessLock:
    def __init__(self,path:Path): self.path=path; self.handle=None
    def __enter__(self):
        self.path.parent.mkdir(parents=True,exist_ok=True); self.handle=self.path.open("a+b"); self.handle.seek(0)
        try:
            if os.name == "nt":
                import msvcrt
                msvcrt.locking(self.handle.fileno(),msvcrt.LK_NBLCK,1)
            else:
                import fcntl
                fcntl.flock(self.handle.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        except OSError as exc: self.handle.close(); raise RuntimeError("Another Gmail intake worker is already running.") from exc
        return self
    def __exit__(self,*_):
        if not self.handle: return
        self.handle.seek(0)
        if os.name == "nt":
            import msvcrt
            msvcrt.locking(self.handle.fileno(),msvcrt.LK_UNLCK,1)
        else:
            import fcntl
            fcntl.flock(self.handle.fileno(),fcntl.LOCK_UN)
        self.handle.close()
