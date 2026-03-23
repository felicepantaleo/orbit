#!/usr/bin/env python3
"""
HEP analysis script for Higgs boson search
"""
import numpy as np
import uproot

def load_events(filename):
    """Load events from ROOT file"""
    with uproot.open(filename) as f:
        tree = f["Events"]
        return tree.arrays(["pt", "eta", "phi", "mass"])

def select_higgs_candidates(events, mass_window=(120, 130)):
    """Select H->gg candidates"""
    mask = (events["mass"] > mass_window[0]) & (events["mass"] < mass_window[1])
    return events[mask]

if __name__ == "__main__":
    events = load_events("data.root")
    candidates = select_higgs_candidates(events)
    print(f"Found {len(candidates)} Higgs candidates")
